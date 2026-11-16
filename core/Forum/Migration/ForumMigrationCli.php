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
        $allowed=match($command){'forum:import:status'=>['job'],'forum:import:map'=>['job','kind','id'],'forum:import:plan'=>['snapshot','prefix','files','platform','source-version'],'forum:import:run'=>['snapshot','prefix','files','batch','steps','confirm','platform','source-version'],default=>[]};
        if(array_diff(array_keys($opts),$allowed))throw new \InvalidArgumentException('Unknown import option');
        $db=new DatabaseManager($config);
        if($command==='forum:import:status')return ForumMigrationEngine::report($db,$opts['job']??'');
        if($command==='forum:import:map')return ForumMigrationEngine::mapping($db,$opts['job']??'',$opts['kind']??'',(int)($opts['id']??0));
        if(!in_array($command,['forum:import:plan','forum:import:run'],true))throw new \InvalidArgumentException('Unknown import command');
        $dsn=getenv('FORUM_IMPORT_SOURCE_DSN')?:'';
        $snapshot=$opts['snapshot']??'';$platform=$opts['platform']??'phpbb';
        $defaultPrefix=match($platform){'phpbb'=>'phpbb_','xenforo'=>'xf_','mybb'=>'mybb_','smf'=>'smf_',default=>throw new \InvalidArgumentException('Unknown platform')};
        $prefix=$opts['prefix']??$defaultPrefix;
        if($platform==='phpbb'&&isset($opts['source-version']))throw new \InvalidArgumentException('phpBB version is detected automatically');
        if(!preg_match('/\A[A-Za-z0-9_.-]{1,100}\z/D',$snapshot))throw new \InvalidArgumentException('Set --snapshot to a unique immutable snapshot label');
        if(!str_starts_with($dsn,'mysql:')&&!str_starts_with($dsn,'sqlite:'))throw new \InvalidArgumentException('Set FORUM_IMPORT_SOURCE_DSN to a MySQL/MariaDB or SQLite snapshot');
        if(str_starts_with($dsn,'sqlite:')&&(!is_file(substr($dsn,7))||!is_readable(substr($dsn,7))))throw new \InvalidArgumentException('Source SQLite snapshot must exist');
        $files=isset($opts['files'])?realpath($opts['files']):null;
        if(isset($opts['files'])&&($files===false||!is_dir($files)))throw new \InvalidArgumentException('Source attachment directory not found');
        // The credentials remain process environment only. Avoid emitting connection exception payloads.
        try{$pdo=new PDO($dsn,getenv('FORUM_IMPORT_SOURCE_USER')?:null,getenv('FORUM_IMPORT_SOURCE_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
        catch(\PDOException){throw new \RuntimeException('Cannot connect to forum snapshot');}
        $identity=$snapshot."\0".$dsn."\0".($files??'');
        $source=$platform==='phpbb'?new PhpbbSource($pdo,$prefix,$identity):new MultiForumSource($pdo,$prefix,$platform,$opts['source-version']??'', $identity);
        $engine=new ForumMigrationEngine($db,$source,$files);
        if($command==='forum:import:plan')return $engine->plan();
        if(!OperationLock::exclusiveHeld($root)||!is_file($root.'/storage/app/maintenance.json')||OperationLock::active($root)||is_file($root.'/storage/app/extensions/deployment.json')||is_file($root.'/storage/app/backup-restore.json'))throw new \RuntimeException('Enable maintenance and drain all application nodes before import');
        if(($opts['confirm']??'')!==('import-'.$platform))throw new \InvalidArgumentException('Use --confirm=import-'.$platform.' after reviewing the plan');
        $limit=filter_var($opts['batch']??'100',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>500]]);
        $steps=filter_var($opts['steps']??'1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]]);
        if($limit===false||$steps===false)throw new \InvalidArgumentException('Invalid batch or step count');
        if(!$engine->exists()){
            $engine->plan(); // Validate source schema before taking the backup.
            $backup=(new BackupManager($root,$config))->capture();
            $engine->start($backup['id']);
        }
        $report=$engine->status();
        if($report['job']['state']!=='complete' && $source->plan()!==$report['job']['plan'])throw new \RuntimeException('Source snapshot changed; restore backup before restarting');
        for($i=0;$i<$steps&&$report['job']['state']!=='complete';$i++)$report=$engine->step($limit);
        return $engine->status();
    }
}
