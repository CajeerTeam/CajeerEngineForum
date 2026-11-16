<?php
declare(strict_types=1);
use CajeerEngine\Installer\InstallerService;
use CajeerEngine\Installer\InstallerEnvironment;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Cache\RedisConnection;
$root='/srv/forum'; $phase='configuration';
function containerSecret(string $name): string {
    $value=trim((string)file_get_contents('/tmp/ce-'.$name));
    if (!preg_match('/^[A-Za-z0-9_-]{24,128}$/D',$value)) throw new RuntimeException('Invalid secret format');
    return $value;
}
try {
    umask(0077); ini_set('display_errors','0'); ini_set('log_errors','0');
    require $root.'/vendor/autoload.php';
    $domain=getenv('CE_DOMAIN')?:''; $port=getenv('CE_HTTPS_PORT')?:'443';
    if (!filter_var($domain,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||strtolower($domain)!==$domain||!preg_match('/^[1-9][0-9]{0,4}$/D',$port)||(int)$port<1||(int)$port>65535) throw new RuntimeException('Invalid HTTPS origin');
    $origin='https://'.$domain.($port==='443'?'':':'.$port);
    $email=getenv('CE_ADMIN_EMAIL')?:'';
    $dbPassword=containerSecret('db_password'); $redisPassword=containerSecret('redis_password');
    $queue=getenv('CE_QUEUE_DRIVER')?:'pgsql';
    if (!in_array($queue,['pgsql','redis'],true)) throw new RuntimeException('Unsupported queue driver');
    $namespace=getenv('CE_NAMESPACE')?:'';
    if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$namespace)) throw new RuntimeException('Invalid Redis namespace');
    $runtime=['REDIS_HOST'=>'redis','REDIS_PORT'=>'6379','REDIS_PASSWORD'=>$redisPassword,'REDIS_DATABASE'=>'0','FORUM_REDIS_NAMESPACE'=>$namespace,'FORUM_CACHE_ENABLED'=>'true','CACHE_DRIVER'=>'redis','RATE_LIMIT_DRIVER'=>'redis','QUEUE_DRIVER'=>$queue,'QUEUE_REDIS_PREFIX'=>'ce:queue:'.$namespace.':','SCHEDULER_DRIVER'=>'pgsql','BACKUP_KEY_FILE'=>'/run/ce-keys/backup.key','UPDATE_TRUSTED_PUBLIC_KEY_FILE'=>'/opt/ce-release.pub','UPDATE_WEB_UPGRADE_ENABLED'=>'false'];
    $key=base64_decode(trim((string)file_get_contents('/run/ce-keys/backup.key')),true);
    if (!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES||(fileperms('/run/ce-keys/backup.key')&0077)!==0) throw new RuntimeException('Invalid backup key');
    sodium_memzero($key);
    $phase='database';
    $pdo=new PDO('pgsql:host=postgres;port=5432;dbname=cajeerengine;sslmode=disable;connect_timeout=5','cajeerengine',$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ((int)$pdo->query('SHOW server_version_num')->fetchColumn()<170000) throw new RuntimeException('PostgreSQL 17+ required');
    if ((int)$pdo->query("SELECT count(*) FROM pg_roles r JOIN pg_database d ON d.datdba=r.oid WHERE d.datname=current_database() AND r.rolname=current_user AND NOT r.rolsuper AND r.rolcreatedb")->fetchColumn()!==1) throw new RuntimeException('Invalid database role');
    $phase='redis'; foreach ($runtime as $k=>$v) putenv($k.'='.$v);
    $redis=RedisConnection::open(); if (!$redis->ping()) throw new RuntimeException('Redis unavailable'); $redis->close();
    $env=new InstallerEnvironment($root.'/.env');
    $phase='installation';
    if (!is_file($root.'/storage/app/installed.lock')) {
        // Never seed an arbitrary nonempty database. A failed first install stays closed.
        if (is_file($root.'/.env') || (int)$pdo->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_%' AND c.relkind IN ('r','p','v','m','f','S')")->fetchColumn()!==0) throw new RuntimeException('Partial or foreign installation; recover explicitly');
        $admin=containerSecret('admin_password');
        $result=(new InstallerService($root,$runtime+['OBSERVABILITY_METRICS_TOKEN'=>bin2hex(random_bytes(32))]))->install(['db'=>'pgsql','db_host'=>'postgres','db_port'=>5432,'db_name'=>'cajeerengine','db_user'=>'cajeerengine','db_password'=>$dbPassword,'db_sslmode'=>'disable','app_url'=>$origin,'domain'=>$domain,'admin_email'=>$email,'admin_password'=>$admin,'production'=>true,'registration'=>false,'cache_driver'=>'redis','queue_driver'=>$queue,'search_driver'=>'pgsql','source'=>'container-deploy']);
        if (!($result['ok']??false)) throw new RuntimeException('Installer failed');
    }
    $phase='persisted-configuration';
    $saved=$env->read();
    // Never silently change queue, credentials, namespace or public origin on restart.
    foreach ($runtime+['DB_HOST'=>'postgres','DB_DATABASE'=>'cajeerengine','DB_USERNAME'=>'cajeerengine','DB_PASSWORD'=>$dbPassword,'APP_URL'=>$origin,'DB_DRIVER'=>'pgsql','DB_SSLMODE'=>'disable'] as $k=>$v) if (($saved[$k]??null)!==$v) throw new RuntimeException('Persisted runtime configuration differs');
    foreach ($saved as $k=>$v) putenv($k.'='.$v);
    $phase='database-ledger';
    $db=new DatabaseManager(new ConfigRepository($root));
    $ledger=(new \CajeerEngine\Database\MigrationRunner($db->connection(),$root.'/migrations'))->verifyApplied($db->migrationDriver());
    if (!$ledger['ok'] || !(new \CajeerEngine\Forum\ForumDatabaseCheck($db))->check()['ok']) throw new RuntimeException('Schema verification failed');
    $phase='recovery-preflight';
    if ($queue==='pgsql') (new \CajeerEngine\Backup\DatabaseBackup($root,new ConfigRepository($root)))->assertRecoveryReady();
    $phase='ecosystem';
    $check=(new \CajeerEngine\Forum\Service\ForumEcosystemCheck($db,new ConfigRepository($root)))->check();
    if (!($check['ok']??false)) throw new RuntimeException('Ecosystem not ready');
    if (\CajeerEngine\Update\OperationLock::maintenance($root)) throw new RuntimeException('Recovery or maintenance active');
    echo "Forum container installation ready\n";
} catch (Throwable $e) { fwrite(STDERR,'Container initialization failed: phase='.$phase.' type='.$e::class."\n"); exit(1); }
