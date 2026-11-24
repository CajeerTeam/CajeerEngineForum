<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Extension\VersionConstraint;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Cache\RedisConnection;
use CajeerEngine\Forum\Service\ForumRuntimeCache;

/** Requirements are read only from the authenticated release.json, never release notes. */
final class UpdateCompatibility
{
    public static function validate(mixed $policy,string $target): void
    {
        if(!is_array($policy)||($policy['schema']??null)!==1||!is_string($policy['min_from']??null)
            ||ReleasePath::compare($policy['min_from'],$target)>=0||!is_string($policy['php']??null)
            ||!is_array($policy['extensions']??null)||!array_is_list($policy['extensions'])||count($policy['extensions'])>64
            ||!is_array($policy['database']??null)||!is_string($policy['redis_min']??null))throw new \RuntimeException('Invalid signed upgrade compatibility policy');
        VersionConstraint::assertValid($policy['php']);
        foreach($policy['extensions'] as $ext)if(!is_string($ext)||!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$ext))throw new \RuntimeException('Invalid required PHP extension');
        foreach($policy['database'] as $driver=>$min)if(!in_array($driver,['pgsql','mysql','mariadb','sqlite'],true)||!self::validMinimum($min))throw new \RuntimeException('Invalid database requirement');
        if($policy['database']===[]||!self::validMinimum($policy['redis_min']))throw new \RuntimeException('Invalid runtime requirement');
    }

    private static function validMinimum(mixed $v): bool { return is_string($v)&&preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D',$v)===1; }

    public static function assertSource(array $policy,string $current,string $target): void
    {
        self::validate($policy,$target);
        if(ReleasePath::compare($current,$policy['min_from'])<0)throw new \RuntimeException('Intermediate update required: install at least '.$policy['min_from'].' before '.$target);
    }

    /** Pure evaluation is also used by regression tests; no raw server strings or credentials in reports. */
    public static function evaluate(array $policy,array $runtime): array
    {
        $checks=[];
        $checks['php']=VersionConstraint::matches($runtime['php'],$policy['php']);
        foreach($policy['extensions'] as $ext)$checks['extension:'.$ext]=in_array($ext,$runtime['extensions'],true);
        $driver=$runtime['database_driver']; $minimum=$policy['database'][$driver]??null;
        $checks['database']=is_string($minimum)&&self::validMinimum($runtime['database_version']??null)&&version_compare($runtime['database_version'],$minimum,'>=');
        if($runtime['redis_enabled'])$checks['redis']=self::validMinimum($runtime['redis_version']??null)&&version_compare($runtime['redis_version'],$policy['redis_min'],'>=');
        return ['ok'=>!in_array(false,$checks,true),'checks'=>$checks,'database_driver'=>$driver,'database_version'=>$runtime['database_version']??null,'redis_enabled'=>$runtime['redis_enabled'],'redis_version'=>$runtime['redis_version']??null,'php_version'=>$runtime['php'],'min_from'=>$policy['min_from']];
    }

    public static function databaseIdentity(string $driver,string $raw): array
    {
        if($driver==='mysql'&&stripos($raw,'mariadb')!==false){$driver='mariadb';$raw=preg_replace('/^5\.5\.5-/','',$raw);}
        $version=preg_match('/^([0-9]+\.[0-9]+(?:\.[0-9]+)?)/D',$raw,$match)?$match[1]:null;
        return [$driver,$version];
    }

    public static function inspect(array $manifest,ConfigRepository $config): array
    {
        if(!isset($manifest['upgrade_compatibility']))return ['ok'=>true,'legacy_policy'=>true,'checks'=>[]];
        $policy=$manifest['upgrade_compatibility']; self::validate($policy,$manifest['version']);
        $db=new DatabaseManager($config); $driver=$db->driver(); $version=null;
        // Never create a missing SQLite database as a side-effect of a preflight.
        $sqlite=$config->string('database.connections.sqlite.database');
        $sqlitePath=str_starts_with($sqlite,'/')?$sqlite:$config->rootPath().'/'.$sqlite;
        if($driver!=='sqlite'||($sqlite!==''&&is_file($sqlitePath))){
            try{[$driver,$version]=self::databaseIdentity($driver,(string)$db->connection()->getAttribute(\PDO::ATTR_SERVER_VERSION));}catch(\Throwable){}finally{$db->disconnect();}
        }
        $redisEnabled=$config->string('queue.default')==='redis'||$config->string('cache.default')==='redis'||getenv('RATE_LIMIT_DRIVER')==='redis'||ForumRuntimeCache::enabled();
        $redisVersion=null;$redis=null;
        if($redisEnabled){try{$redis=RedisConnection::open($config->rootPath());$info=$redis->info('server');$redisVersion=self::databaseIdentity('redis',(string)($info['redis_version']??''))[1];}catch(\Throwable){}finally{if($redis!==null)try{$redis->close();}catch(\Throwable){}}}
        return self::evaluate($policy,['php'=>PHP_VERSION,'extensions'=>array_map('strtolower',get_loaded_extensions()),'database_driver'=>$driver,'database_version'=>$version,'redis_enabled'=>$redisEnabled,'redis_version'=>$redisVersion]);
    }

    public static function requireRuntime(array $manifest,ConfigRepository $config): array
    {
        $report=self::inspect($manifest,$config);
        if(!$report['ok'])throw new \RuntimeException('Target runtime incompatible: '.implode(', ',array_keys(array_filter($report['checks'],static fn($ok)=>!$ok))));
        return $report;
    }
}
