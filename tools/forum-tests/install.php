<?php
/** Disposable HTTP/E2E fixture. Not a web endpoint. */
declare(strict_types=1);
use CajeerEngine\Installer\{InstallerService, InstallerEnvironment};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

if (PHP_SAPI !== 'cli' || count($argv)!==3) exit(2);
$root=realpath($argv[1]); $url=$argv[2];
if (!$root || !is_file($root.'/.forum-test-fixture') || is_file($root.'/.env') || !preg_match('~^http://127\.0\.0\.1:[0-9]+$~D',$url)) throw new RuntimeException('Disposable fixture required');
require $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')?:'sqlite';
if (!in_array($driver,['sqlite','pgsql'],true)) throw new RuntimeException('Unsupported test driver');
$dbName=$root.'/storage/database/forum.sqlite';
if ($driver==='pgsql') {
    $dbName=(string)getenv('FORUM_TEST_NETWORK_DB');
    if (!preg_match('/^forum_test_[a-z0-9_]+$/D',$dbName) || getenv('DB_HOST')!=='127.0.0.1') throw new RuntimeException('Dedicated loopback forum_test_* database required');
    putenv('DB_DRIVER=pgsql'); putenv('DB_DATABASE='.$dbName);
    $db=new DatabaseManager(new ConfigRepository($root));
    if ((int)$db->connection()->query("SELECT COUNT(*) FROM pg_catalog.pg_tables WHERE schemaname='public'")->fetchColumn()!==0) throw new RuntimeException('Test database must be empty');
    unset($db);
}
$result=(new InstallerService($root))->install([
    'db'=>$driver,'db_name'=>$dbName,'db_host'=>'127.0.0.1','db_port'=>getenv('DB_PORT')?:5432,
    'db_user'=>getenv('DB_USERNAME')?:'forum_ci','db_password'=>getenv('DB_PASSWORD')?:'', 'db_sslmode'=>'disable',
    'app_url'=>$url,'app_name'=>'Forum Network Test','category_name'=>'Test community','board_name'=>'Test board',
    'admin_name'=>'Test Administrator','admin_email'=>'admin@example.test','admin_password'=>'AdminPass12345!',
    'registration'=>true,'production'=>false,
]);
if (!$result['ok']) throw new RuntimeException('Fixture install failed');
(new InstallerEnvironment($root.'/.env'))->write([
    'APP_ENV'=>'testing','APP_DEBUG'=>'false','FORUM_REQUIRE_EMAIL_VERIFICATION'=>'true',
    'FORUM_SMTP_HOST'=>'127.0.0.1','FORUM_SMTP_PORT'=>'1025','FORUM_SMTP_SECURITY'=>'plain',
    'FORUM_SMTP_FROM'=>'forum@example.test','FORUM_SMTP_USERNAME'=>'','FORUM_SMTP_PASSWORD'=>'',
    'RATE_LIMIT_AUTH'=>'160','RATE_LIMIT_GLOBAL'=>'1000','RATE_LIMIT_WINDOW_SECONDS'=>'600',
    'PASSWORD_ARGON2_MEMORY'=>'8192','PASSWORD_ARGON2_TIME'=>'2',
],$root.'/.env.example');
echo "Fixture installed ($driver)\n";
