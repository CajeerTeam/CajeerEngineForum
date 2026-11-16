<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

use PDO;

/** Read-only phpBB 3.3 snapshot. No source credentials are stored in the target. */
final class PhpbbSource implements ForumSource
{
    public const PHASES=['groups','users','forums','topics','posts','finalize','attachments','polls','votes','watches','board_watches'];
    private const TABLES=['groups'=>['groups','group_id'],'users'=>['users','user_id'],'forums'=>['forums','left_id'],'topics'=>['topics','topic_id'],'posts'=>['posts','post_id'],'finalize'=>['topics','topic_id'],'attachments'=>['attachments','attach_id'],'polls'=>['topics','topic_id'],'votes'=>['topics','topic_id'],'watches'=>['topics','topic_id'],'board_watches'=>['forums','forum_id']];
    public readonly string $identity;
    public readonly string $version;
    private string $driver;
    public function __construct(private PDO $pdo, private string $prefix, string $snapshotIdentity)
    {
        if(!preg_match('/\A[A-Za-z0-9_]{1,40}\z/D',$prefix))throw new \InvalidArgumentException('Invalid phpBB table prefix');
        $this->driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        if($this->driver==='mysql'){ $pdo->exec('SET NAMES utf8mb4');$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET SESSION TRANSACTION READ ONLY'); }
        elseif($this->driver==='sqlite') $pdo->exec('PRAGMA query_only=ON');
        else throw new \InvalidArgumentException('Source must be MySQL/MariaDB or a SQLite snapshot');
        $pdo->beginTransaction();
        $this->version=(string)$this->query('SELECT config_value FROM '.$this->table('config')." WHERE config_name='version'")->fetchColumn();
        if(!preg_match('/\A3\.3\.\d+(?:[-a-zA-Z0-9.]*)?\z/D',$this->version))throw new \RuntimeException('Only phpBB 3.3 snapshots are supported');
        $this->identity=hash('sha256',$snapshotIdentity."\0".$prefix."\0".$this->version);
    }
    public function platform(): string {return 'phpbb';}
    public function identity(): string {return $this->identity;}
    public function version(): string {return $this->version;}
    public function unsupported(): array {return ['source ACL translation','password hashes','private messages','banlist','anonymous poll votes','remote media','profile fields'];}
    private function table(string $name): string {return $this->prefix.$name;}
    private function query(string $sql,array $params=[]): \PDOStatement {$s=$this->pdo->prepare($sql);$s->execute($params);return $s;}
    private ?array $planned=null;
    public function plan(): array
    {
        if(!$this->pdo->inTransaction())throw new \RuntimeException('Source snapshot transaction ended');
        if($this->planned!==null)return $this->planned;
        $counts=[];
        foreach(self::TABLES as $phase=>[$table,$key]){
            $filter=in_array($phase,['polls','votes'],true)?" WHERE poll_title<>''":'';
            $row=$this->query('SELECT COUNT(*) AS n,COALESCE(MAX('.$key.'),0) AS high FROM '.$this->table($table).$filter)->fetch(PDO::FETCH_ASSOC);
            $counts[$phase]=['count'=>(int)$row['n'],'high'=>(int)$row['high']];
        }
        // Explicitly report objects whose semantics cannot safely be translated.
        foreach(['privmsgs','banlist','acl_users','acl_groups','poll_votes','forums_watch','topics_watch'] as $name){
            $counts['source_'.$name]=(int)$this->query('SELECT COUNT(*) FROM '.$this->table($name))->fetchColumn();
        }
        // Verify actual required columns before taking the target backup.
        foreach(self::PHASES as $phase)$this->batch($phase,0,$counts[$phase]['high'],1);
        $this->memberships(0);$this->options(0);$this->ballot(0,0);
        foreach(['watches','board_watches','votes'] as $kind)$this->watchers($kind,0,0,1);
        return $this->planned=$counts;
    }
    public function batch(string $phase,int $cursor,int $high,int $limit): array
    {
        [$table,$key]=self::TABLES[$phase]??throw new \InvalidArgumentException('Unknown phase');
        $filter=in_array($phase,['polls','votes'],true)?" AND poll_title<>''":'';
        // No passwords, secrets, IP addresses, private messages, or ACL payloads are selected.
        $fields=match($phase){
            'groups'=>'group_id,group_name,group_desc',
            'users'=>'user_id,user_type,username,user_email,user_regdate',
            'forums'=>'forum_id,parent_id,left_id,forum_type,forum_name,forum_desc,forum_status',
            'topics'=>'topic_id,forum_id,topic_poster,topic_title,topic_time,topic_last_post_time,topic_status,topic_type,topic_visibility',
            'posts'=>'post_id,topic_id,forum_id,poster_id,CASE WHEN LENGTH(post_text)<=1048576 THEN post_text ELSE NULL END AS post_text,bbcode_uid,post_time,post_edit_time,post_visibility',
            'attachments'=>'attach_id,post_msg_id,topic_id,in_message,poster_id,is_orphan,physical_filename,real_filename,filesize,filetime',
            'polls'=>'topic_id,poll_title,poll_start,poll_length,poll_max_options,poll_vote_change',
            'watches','votes','finalize'=>'topic_id',
            'board_watches'=>'forum_id',
        };
        // Limit is validated by the engine; literal LIMIT avoids PDO string binding differences.
        return $this->query('SELECT '.$fields.' FROM '.$this->table($table)." WHERE $key>? AND $key<=?$filter ORDER BY $key LIMIT ".max(1,min(500,$limit)),[$cursor,$high])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function memberships(int $user): array {return $this->query('SELECT group_id FROM '.$this->table('user_group').' WHERE user_id=? AND user_pending=0 ORDER BY group_id LIMIT 1001',[$user])->fetchAll(PDO::FETCH_COLUMN);}
    public function options(int $topic): array {return $this->query('SELECT poll_option_id,poll_option_text FROM '.$this->table('poll_options').' WHERE topic_id=? ORDER BY poll_option_id LIMIT 21',[$topic])->fetchAll(PDO::FETCH_ASSOC);}
    public function watchers(string $kind,int $id,int $after,int $limit): array
    {
        [$table,$column]=match($kind){'watches'=>['topics_watch','topic_id'],'board_watches'=>['forums_watch','forum_id'],'votes'=>['poll_votes','topic_id']};
        $user=$kind==='votes'?'vote_user_id':'user_id';
        return $this->query('SELECT DISTINCT '.$user.' FROM '.$this->table($table)." WHERE $column=? AND $user>? ORDER BY $user LIMIT ".max(1,min(500,$limit)),[$id,$after])->fetchAll(PDO::FETCH_COLUMN);
    }
    public function ballot(int $topic,int $user): array {return $this->query('SELECT DISTINCT poll_option_id FROM '.$this->table('poll_votes').' WHERE topic_id=? AND vote_user_id=? ORDER BY poll_option_id LIMIT 21',[$topic,$user])->fetchAll(PDO::FETCH_COLUMN);}

}
