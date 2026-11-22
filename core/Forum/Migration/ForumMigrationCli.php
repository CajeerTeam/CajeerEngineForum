<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

use CajeerEngine\Backup\BackupManager;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationLock;
use PDO;

/** Called under the exclusive application operation lease for writes. */
final class ForumMigrationCli
{
    public static function run(string $root,ConfigRepository $config,array $argv): array
    {
        if(PHP_SAPI!=='cli')throw new \RuntimeException('Operator CLI required');
        $command=$argv[1]??'';$opts=[];
        foreach(array_slice($argv,2) as $arg){if($arg==='--json')continue;if(!preg_match('/\A--([a-z-]+)=(.*)\z/sD',$arg,$m)||isset($opts[$m[1]]))throw new \InvalidArgumentException('Use --name=value options');$opts[$m[1]]=$m[2];}
        $allowed=match($command){'forum:import:status'=>['job'],'forum:import:map'=>['job','kind','id'],'forum:import:plan'=>['snapshot','prefix','files','avatar-files','platform','source-version'], 'forum:import:discover'=>['snapshot','prefix','files','platform','source-version'],'forum:import:run'=>['snapshot','prefix','files','avatar-files','batch','steps','seconds','verify-backup','progress-only','confirm','platform','source-version'], 'forum:import:accept'=>['job','snapshot','prefix','files','avatar-files','platform','source-version','expect-skips-sha256'],default=>[]};
        if(array_diff(array_keys($opts),$allowed))throw new \InvalidArgumentException('Unknown import option');
        if($command==='forum:import:discover')return self::discoverInvision($opts);
        $db=new DatabaseManager($config);
        if($command==='forum:import:status')return ForumMigrationEngine::report($db,$opts['job']??'');
        if($command==='forum:import:map')return ForumMigrationEngine::mapping($db,$opts['job']??'',$opts['kind']??'',(int)($opts['id']??0));
        if(!in_array($command,['forum:import:plan','forum:import:run','forum:import:accept'],true))throw new \InvalidArgumentException('Unknown import command');
        $dsn=getenv('FORUM_IMPORT_SOURCE_DSN')?:'';
        $snapshot=$opts['snapshot']??'';$platform=$opts['platform']??'phpbb';
        $defaultPrefix=match($platform){'phpbb'=>'phpbb_','xenforo'=>'xf_','mybb'=>'mybb_','smf'=>'smf_','invision'=>'',default=>throw new \InvalidArgumentException('Unknown platform')};
        $prefix=$opts['prefix']??($platform==='invision'?null:$defaultPrefix);
        if($platform==='phpbb'&&isset($opts['source-version']))throw new \InvalidArgumentException('phpBB version is detected automatically');
        if(!preg_match('/\A[A-Za-z0-9_.-]{1,100}\z/D',$snapshot))throw new \InvalidArgumentException('Set --snapshot to a unique immutable snapshot label');
        if(!str_starts_with($dsn,'mysql:')&&!str_starts_with($dsn,'sqlite:'))throw new \InvalidArgumentException('Set FORUM_IMPORT_SOURCE_DSN to a MySQL/MariaDB or SQLite snapshot');
        if(str_starts_with($dsn,'sqlite:')&&(!is_file(substr($dsn,7))||!is_readable(substr($dsn,7))))throw new \InvalidArgumentException('Source SQLite snapshot must exist');
        $files=isset($opts['files'])?realpath($opts['files']):null;
        if(isset($opts['files'])&&($files===false||!is_dir($files)))throw new \InvalidArgumentException('Source attachment directory not found');
        if(isset($opts['avatar-files'])&&$platform!=='xenforo')throw new \InvalidArgumentException('Avatar snapshot only supported for XenForo');
        $avatarFiles=isset($opts['avatar-files'])?realpath($opts['avatar-files']):null;
        if(isset($opts['avatar-files'])&&($avatarFiles===false||!is_dir($avatarFiles)))throw new \InvalidArgumentException('XenForo avatar snapshot directory not found');
        // The credentials remain process environment only. Avoid emitting connection exception payloads.
        try{$pdo=new PDO($dsn,getenv('FORUM_IMPORT_SOURCE_USER')?:null,getenv('FORUM_IMPORT_SOURCE_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
        catch(\PDOException){throw new \RuntimeException('Cannot connect to forum snapshot');}
        $identity=$snapshot."\0".$dsn."\0".($files??'').($avatarFiles===null?'':"\0avatars:".$avatarFiles);
        // Canonical source origin is optional; required to remap absolute XenForo links.
        // It is included in the immutable job identity by MultiForumSource.
        $origin=$platform==='xenforo'?(getenv('FORUM_IMPORT_SOURCE_ORIGIN')?:null):null;
        $source=match($platform){
            'phpbb'=>new PhpbbSource($pdo,$prefix,$identity),
            'invision'=>new InvisionSource($pdo,$prefix,$opts['source-version']??null,$identity,$files),
            default=>new MultiForumSource($pdo,$prefix,$platform,$opts['source-version']??'', $identity,$origin),
        };
        $engine=new ForumMigrationEngine($db,$source,$files,$avatarFiles);
        if($command==='forum:import:plan')return $engine->plan();
        if($platform==='xenforo')self::requireXenForoLedgerWidth($db);
        if($command==='forum:import:accept'){
            if($platform!=='xenforo')throw new \InvalidArgumentException('Acceptance supports XenForo only');
            if(!is_file($root.'/storage/app/maintenance.json')||OperationLock::active($root)
                ||is_file($root.'/storage/app/extensions/deployment.json')
                ||is_file($root.'/storage/app/backup-restore.json'))
                throw new \RuntimeException('Acceptance requires isolated maintenance mode with no active deployment or recovery');
            $job=ForumMigrationEngine::report($db,$opts['job']??'')['job'];
            $backupId=(string)$job['backup_id'];
            if(!preg_match('/\A[0-9]{8}-[0-9]{6}-[a-f0-9]{16}\z/D',$backupId))
                throw new \RuntimeException('Import recovery backup identity is invalid');
            $recovery=(new BackupManager($root,$config))->verify($backupId);
            $result=(new XenForoAcceptance($db,$source,$engine,$files,$avatarFiles))->check($opts['job'], $opts['expect-skips-sha256']??null);
            return $result+['verified_backup'=>['id'=>$backupId,'manifest_sha256'=>$recovery['manifest_sha256']]];
        }
        if(!OperationLock::exclusiveHeld($root)||!is_file($root.'/storage/app/maintenance.json')||OperationLock::active($root)||is_file($root.'/storage/app/extensions/deployment.json')||is_file($root.'/storage/app/backup-restore.json'))throw new \RuntimeException('Enable maintenance and drain all application nodes before import');
        if(($opts['confirm']??'')!==('import-'.$platform))throw new \InvalidArgumentException('Use --confirm=import-'.$platform.' after reviewing the plan');
        $limit=filter_var($opts['batch']??'100',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>500]]);
        $steps=filter_var($opts['steps']??'1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]]);
        $seconds=filter_var($opts['seconds']??'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>86400]]);
        foreach(['verify-backup','progress-only'] as $flag)if(isset($opts[$flag])&&!in_array($opts[$flag],['0','1'],true))throw new \InvalidArgumentException('Use --'.$flag.'=0 or 1');
        if($limit===false||$steps===false||$seconds===false)throw new \InvalidArgumentException('Invalid batch or step count');
        // Check the ecosystem before creating a recovery set or modifying the target.
        (new \CajeerEngine\Forum\Service\ForumThemeService($db))->snapshot();
        \CajeerEngine\Extension\DependencyGraph::order((new \CajeerEngine\Extension\ExtensionRegistry($root,$config))->all());
        if(!$engine->exists()){
            ForumMigrationEngine::assertNoRunning($db);
            $planning=$engine->plan(); // Validate source schema before taking the backup.
            if($platform==='xenforo' && ($planning['counts']['avatars']['count']??0)>0 && $avatarFiles===null)
                throw new \RuntimeException('XenForo avatar snapshot required: supply --avatar-files before capturing a recovery backup');
            if($platform==='xenforo' && ($planning['pipeline_version']??1)===5 && (($planning['counts']['attachments']['count']??0)>0 || ($planning['counts']['conversation_attachments']['count']??0)>0) && $files===null)
                throw new \RuntimeException('XenForo file snapshot required: supply --files=internal_data/attachments before capturing a recovery backup');
            $backup=(new BackupManager($root,$config))->capture();
            $engine->start($backup['id']);
        }
        $report=$engine->progress();
        if($report['job']['state']!=='complete'){
            $backupId=$report['job']['backup_id'];
            if(!preg_match('/\A[0-9]{8}-[0-9]{6}-[a-f0-9]{16}\z/D',$backupId)||!is_file($root.'/storage/app/backups/production/'.$backupId.'/manifest.json')||is_file($root.'/storage/app/backups/production/'.$backupId.'/incomplete.json'))throw new \RuntimeException('Recovery set missing or incomplete; restore it before resuming');
        }
        if($report['job']['state']!=='complete'&&($opts['verify-backup']??'0')==='1')(new BackupManager($root,$config))->verify($report['job']['backup_id']);
        if($report['job']['state']!=='complete' && !$engine->matchesRecordedPlan($report['job']['plan']))throw new \RuntimeException('Source snapshot changed; restore backup before restarting');
        $started=hrtime(true);$completed=0;
        for($i=0;$i<$steps&&$report['job']['state']!=='complete';$i++){
            $report=$engine->step($limit);$completed++;
            if($seconds>0&&(hrtime(true)-$started)/1e9>=$seconds)break;
        }
        return ($report['job']['state']==='complete'||($opts['progress-only']??'0')==='0'?$engine->status():$engine->progress())+['batches'=>$completed,'elapsed_seconds'=>round((hrtime(true)-$started)/1e9,3)];
    }
    /** Real Invision 4.x/5.x source discovery, read-only and independent of the target DB. */
    private static function discoverInvision(array $opts): array
    {
        if(($opts['platform']??'')!=='invision')
            throw new \InvalidArgumentException('Use --platform=invision for Invision source discovery');
        $snapshot=$opts['snapshot']??'';
        if(!preg_match('/\A[A-Za-z0-9_.-]{1,100}\z/D',$snapshot))
            throw new \InvalidArgumentException('Specify a unique immutable --snapshot label');
        $dsn=getenv('FORUM_IMPORT_SOURCE_DSN')?:'';
        if(!str_starts_with($dsn,'mysql:')&&!str_starts_with($dsn,'sqlite:'))
            throw new \InvalidArgumentException('Source must be a MySQL/MariaDB or SQLite frozen snapshot');
        if(str_starts_with($dsn,'sqlite:')&&(!is_file(substr($dsn,7))||!is_readable(substr($dsn,7))))
            throw new \InvalidArgumentException('Source SQLite snapshot not readable');
        $files=isset($opts['files'])?realpath($opts['files']):null;
        if(isset($opts['files'])&&($files===false||!is_dir($files)||!is_readable($files)))
            throw new \InvalidArgumentException('Invision uploads snapshot not readable');
        try{
            $pdo=new PDO($dsn,getenv('FORUM_IMPORT_SOURCE_USER')?:null,getenv('FORUM_IMPORT_SOURCE_PASSWORD')?:null,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        }catch(\PDOException){throw new \RuntimeException('Cannot connect to frozen Invision source');}
        // No target DB, no backup, no migration writes. The connection is placed in
        // a read-only consistent transaction by InvisionSourceDiscovery before probing.
        $discovery=new InvisionSourceDiscovery($pdo,$opts['prefix']??null,$opts['source-version']??null);
        return $discovery->plan($files?:null)+['snapshot'=>$snapshot];
    }

    /** Refuse production imports on MySQL/PostgreSQL until the v5 ledger upgrade is applied. */
    private static function requireXenForoLedgerWidth(DatabaseManager $db): void
    {
        $driver=$db->driver();
        if($driver==='sqlite')return; // SQLite VARCHAR(N) does not enforce N.
        $query=match($driver){
            'pgsql'=>"SELECT character_maximum_length FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='ce_forum_import_map' AND column_name='kind'",
            'mysql'=>"SELECT character_maximum_length FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ce_forum_import_map' AND column_name='kind'",
            default=>throw new \RuntimeException('Unsupported target database driver'),
        };
        $width=$db->connection()->query($query)->fetchColumn();
        if($width===false||(int)$width<24)
            throw new \RuntimeException('XenForo private media ledger requires migration 0079: run php bin/cajeer migrate before importing');
    }
}
