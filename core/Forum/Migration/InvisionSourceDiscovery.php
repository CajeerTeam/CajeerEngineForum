<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

use PDO;

/** Read-only, bounded structural and relational discovery of frozen Invision 4/5 forum snapshots.
 * This does not start an import: importing a snapshot before adapter acceptance would risk data loss.
 */
final class InvisionSourceDiscovery
{
    private string $prefix;
    private string $version;
    private string $driver;
    /** @var array<string,list<string>> */
    private array $columns=[];

    private const REQUIRED = [
        'core_applications'=>['app_directory','app_version'],
        'core_members'=>['member_id','name','email','joined','member_group_id'],
        'core_groups'=>['g_id','g_title'],
        'forums_forums'=>['id','parent_id','name'],
        'forums_topics'=>['tid','forum_id','starter_id','title','start_date'],
        'forums_posts'=>['pid','topic_id','author_id','post_date'],
    ];
    private const OPTIONAL = [
        'core_attachments','core_file_storage','core_message_topics','core_message_posts',
        'core_follow','core_reputation_index','forums_polls','forums_voters',
        'forums_archive_posts','core_pfields_content','core_members_warn_logs',
    ];
    private const KEYS = [
        'core_members'=>'member_id', 'core_groups'=>'g_id', 'forums_forums'=>'id',
        'forums_topics'=>'tid', 'forums_posts'=>'pid',
    ];

    public function __construct(private readonly PDO $pdo, ?string $prefix=null, ?string $assertVersion=null)
    {
        $this->driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if(!in_array($this->driver,['mysql','sqlite'],true))throw new \InvalidArgumentException('Invision requires a MySQL/MariaDB or SQLite snapshot');
        if($prefix!==null&&!preg_match('/\A[a-zA-Z0-9_]{0,40}\z/D',$prefix))throw new \InvalidArgumentException('Invalid Invision table prefix');
        if($assertVersion!==null&&!preg_match('/\A[45]\.\d+(?:\.\d+){0,2}\z/D',$assertVersion))
            throw new \InvalidArgumentException('Expected Invision source version must be 4.x or 5.x');
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        if($this->driver==='mysql'){
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        }else{
            $pdo->exec('PRAGMA query_only=ON');
        }
        $pdo->beginTransaction();
        $names=$this->listTables();
        $this->prefix=$prefix??self::detectPrefix($names);
        foreach(self::REQUIRED as $name=>$fields){
            $actual=$this->prefix.$name;
            if(!in_array($actual,$names,true))throw new \RuntimeException('Invision source missing table '.$actual);
            $found=$this->listColumns($actual);
            $missing=array_diff($fields,$found);
            if($missing!==[])throw new \RuntimeException('Invision source '.$actual.' missing columns '.implode(',',$missing));
            $this->columns[$name]=$found;
        }
        foreach(self::OPTIONAL as $name){
            if(in_array($this->prefix.$name,$names,true))$this->columns[$name]=$this->listColumns($this->prefix.$name);
        }
        if(!in_array('post',$this->columns['forums_posts'],true) && !in_array('post_content',$this->columns['forums_posts'],true))
            throw new \RuntimeException('Invision posts require post or post_content body column');
        $this->version=$this->installedVersion($assertVersion);
        // Check the Forums application itself: a partially upgraded installation
        // must never be misclassified as a stable 4.x/5.x source.
        $this->verifyForumsVersion();
    }

    /** Distinguish real IPS table prefixes without guessing on ambiguous multi-install snapshots. */
    public static function detectPrefix(array $tables): string
    {
        $names=array_fill_keys($tables,true);$possible=[];
        foreach($tables as $table){
            if(!str_ends_with($table,'core_applications'))continue;
            $prefix=substr($table,0,-strlen('core_applications'));
            if(!preg_match('/\A[A-Za-z0-9_]{0,40}\z/D',$prefix))continue;
            if(isset($names[$prefix.'core_members'],$names[$prefix.'forums_topics'],$names[$prefix.'forums_posts']))$possible[]=$prefix;
        }
        $possible=array_values(array_unique($possible));
        if(count($possible)!==1)throw new \RuntimeException('Invision prefix ambiguous or missing; specify --prefix explicitly');
        return $possible[0];
    }

    public function plan(?string $files=null): array
    {
        if(!$this->pdo->inTransaction())throw new \RuntimeException('Invision discovery snapshot transaction ended');
        if($files!==null && (!is_dir($files)||!is_readable($files)))throw new \InvalidArgumentException('Invision local file snapshot unavailable');
        $counts=[];
        foreach(self::KEYS as $table=>$key){
            $actual=$this->t($table);
            $row=$this->pdo->query("SELECT COUNT(*) AS n, COALESCE(MAX($key),0) AS high, COUNT(DISTINCT $key) AS unique_count FROM $actual")->fetch(PDO::FETCH_ASSOC);
            $count=(int)$row['n'];
            if($count!==(int)$row['unique_count'] || ($count>0 && (int)$this->scalar("SELECT COUNT(*) FROM $actual WHERE $key IS NULL OR $key<=0")>0))
                throw new \RuntimeException('Invision source invalid or duplicate key in '.$table);
            $counts[$table]=['count'=>$count,'high'=>(int)$row['high']];
        }
        // Detect dangling references before assigning new IDs in a future import.
        $orphans=[
            'topics_missing_forum'=>$this->countJoin('forums_topics','forum_id','forums_forums','id'),
            'posts_missing_topic'=>$this->countJoin('forums_posts','topic_id','forums_topics','tid'),
        ];
        $blockers=[];$warnings=[];
        foreach($orphans as $kind=>$amount)if($amount>0)$blockers[]=['code'=>$kind,'count'=>$amount];
        $missingMembers=(int)$this->scalar('SELECT COUNT(*) FROM '.$this->t('forums_posts').' p LEFT JOIN '.$this->t('core_members').' m ON p.author_id=m.member_id WHERE p.author_id>0 AND m.member_id IS NULL');
        if($missingMembers>0)$warnings[]=['code'=>'deleted_post_authors','count'=>$missingMembers];
        $unmappedGroups=(int)$this->scalar('SELECT COUNT(*) FROM '.$this->t('core_members').' m LEFT JOIN '.$this->t('core_groups').' g ON m.member_group_id=g.g_id WHERE g.g_id IS NULL');
        if($unmappedGroups>0)$warnings[]=['code'=>'members_without_primary_group','count'=>$unmappedGroups];
        $optional=[];
        foreach(self::OPTIONAL as $name){
            if(!isset($this->columns[$name]))continue;
            $optional[$name]=['count'=>(int)$this->scalar('SELECT COUNT(*) FROM '.$this->t($name))];
        }
        $filesRequired=($optional['core_attachments']['count']??0)>0;
        if($filesRequired&&$files===null)$blockers[]=['code'=>'attachment_snapshot_required','count'=>$optional['core_attachments']['count']];
        if(($optional['forums_archive_posts']['count']??0)>0)$warnings[]=['code'=>'archived_posts_separate','count'=>$optional['forums_archive_posts']['count']];
        $tree=$this->treeIntegrity();
        if($tree['cycles']>0)$blockers[]=['code'=>'cyclic_forum_tree','count'=>$tree['cycles']];
        if($tree['dangling_parents']>0)$blockers[]=['code'=>'dangling_forum_parent','count'=>$tree['dangling_parents']];
        // Snapshot structural digest intentionally excludes secrets, private message content and emails.
        $columns=$this->columns;ksort($columns);
        foreach($columns as &$names)sort($names,SORT_STRING);unset($names);
        $fingerprint=hash('sha256',json_encode(['version'=>$this->version,'prefix'=>$this->prefix,'columns'=>$columns,'counts'=>$counts,'optional'=>$optional],JSON_THROW_ON_ERROR));
        return [
            'ok'=>$blockers===[], 'platform'=>'invision', 'version'=>$this->version,'major_version'=>(int)$this->version[0],
            'prefix'=>$this->prefix,'database_driver'=>$this->driver,
            'schema_sha256'=>$fingerprint, 'counts'=>$counts,'optional_counts'=>$optional,
            'integrity'=>['orphans'=>$orphans,'forum_tree'=>$tree],
            'file_storage'=>['local_snapshot_supplied'=>$files!==null,'has_attachments'=>$filesRequired,
                'storage_methods_need_operator_review'=>isset($optional['core_file_storage'])],
            'blockers'=>$blockers,'warnings'=>$warnings,
            'import_supported'=>count(array_filter($blockers,static fn(array $b):bool=>$b['code']!=='attachment_snapshot_required'))===0,
            'core_import_only'=>false,
            'next_step'=>'Invision v8 plan/run supports core data, local media, supported polls, profiles, reactions, follows and privacy-checked conversations; unsupported layouts fail closed or are reported before cutover',
        ];
    }

    private function installedVersion(?string $assertVersion): string
    {
        $sql='SELECT app_version FROM '.$this->t('core_applications').' WHERE app_directory=?';
        $q=$this->pdo->prepare($sql);$q->execute(['core']);
        $versions=$q->fetchAll(PDO::FETCH_COLUMN);
        if(count($versions)!==1)throw new \RuntimeException('Invision source must contain exactly one core application version');
        $version=trim((string)$versions[0]);
        if(!preg_match('/\A[45]\.\d+(?:\.\d+){0,2}\z/D',$version))throw new \RuntimeException('Unsupported or unrecognized Invision 4/5 installed core version');
        if($assertVersion!==null && $version!==$assertVersion)throw new \RuntimeException('Invision installed version does not match --source-version');
        return $version;
    }
    private function verifyForumsVersion(): void
    {
        $q=$this->pdo->prepare('SELECT app_version FROM '.$this->t('core_applications').' WHERE app_directory=?');
        $q->execute(['forums']);
        $versions=$q->fetchAll(PDO::FETCH_COLUMN);
        if(count($versions)!==1)throw new \RuntimeException('Invision Forums application is missing or duplicated');
        $forums=trim((string)$versions[0]);
        if(!preg_match('/\A[45]\.\d+(?:\.\d+){0,2}\z/D',$forums)||$forums[0]!==$this->version[0])
            throw new \RuntimeException('Invision core and Forums application major versions are inconsistent');
    }
    private function countJoin(string $child,string $foreign,string $parent,string $primary): int
    {
        return (int)$this->scalar('SELECT COUNT(*) FROM '.$this->t($child).' c LEFT JOIN '.$this->t($parent).' p ON c.'.$foreign.'=p.'.$primary.' WHERE p.'.$primary.' IS NULL');
    }
    private function scalar(string $sql): mixed {return $this->pdo->query($sql)->fetchColumn();}
    private function t(string $table): string {return $this->prefix.$table;}
    private function listTables(): array
    {
        if($this->driver==='sqlite')return $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        return $this->pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
    }
    private function listColumns(string $name): array
    {
        if($this->driver==='sqlite'){
            // Name composed only of trusted, validated prefix and code-owned table identifier.
            $rows=$this->pdo->query('PRAGMA table_info("'.$name.'")')->fetchAll(PDO::FETCH_ASSOC);
            return array_map('strtolower',array_column($rows,'name'));
        }
        $s=$this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $s->execute([$name]);return array_map('strtolower',$s->fetchAll(PDO::FETCH_COLUMN));
    }
    /** Scan only forum IDs and parents, with O(N) graph traversal and a 50k safety cap. */
    private function treeIntegrity(): array
    {
        $rows=$this->pdo->query('SELECT id,parent_id FROM '.$this->t('forums_forums'))->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>50000)throw new \RuntimeException('Invision forum tree exceeds safe preflight node limit');
        $parents=[];
        foreach($rows as $row)$parents[(int)$row['id']]=(int)$row['parent_id'];
        $dangling=0;
        foreach($parents as $parent){
            // Both 0 and -1 appear as root sentinels in IPS installations.
            if($parent>0&&!isset($parents[$parent]))$dangling++;
        }
        $state=[];$cycles=0;
        foreach($parents as $id=>$_){
            if(isset($state[$id]))continue;
            $path=[];$cursor=$id;
            while($cursor>0&&isset($parents[$cursor])&&!isset($state[$cursor])){
                $state[$cursor]=1;$path[]=$cursor;
                $cursor=$parents[$cursor];
            }
            if($cursor>0&&($state[$cursor]??null)===1)$cycles++;
            foreach($path as $node)$state[$node]=2;
        }
        return ['cycles'=>$cycles,'dangling_parents'=>$dangling];
    }
}
