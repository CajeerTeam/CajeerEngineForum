<?php
/** Real installer + database integration. PG database names must be disposable and empty. */
declare(strict_types=1);

use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\{RoleRepository,UserRepository};
use CajeerEngine\Forum\ForumDatabaseCheck;
use CajeerEngine\Forum\Repository\{ThreadRepository,PostRepository};
use CajeerEngine\Installer\{InstallerEnvironment,InstallerService,SetupToken};
use CajeerEngine\Release\ReleaseBuilder;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Support\Uuid;
use Symfony\Component\Dotenv\Dotenv;

$root=dirname(__DIR__); require $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')==='pgsql'?'pgsql':'sqlite';
$tmp=sys_get_temp_dir().'/forum-installer-'.bin2hex(random_bytes(8)); mkdir($tmp,0700);
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function rejects(callable $fn,string $message,string $contains=''): void {
    try {$fn();} catch(Throwable $e){check($contains===''||str_contains($e->getMessage(),$contains),$message.' ('.$e->getMessage().')');return;}
    throw new RuntimeException('Accepted: '.$message);
}
function cleanup(string $path): void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($path);
}
function setenv(string $key,string $value): void {putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
try {
    $zip=(new ReleaseBuilder($root))->build(false,'source',$tmp.'/release')['target'];
    (new PharData($zip))->extractTo($tmp.'/fresh');
    $site=$tmp.'/fresh/cajeerengine';
    symlink($root.'/vendor',$site.'/vendor');
    $dbName=$driver==='sqlite'?$site.'/storage/database/forum.sqlite':(getenv('FORUM_TEST_INSTALL_DB')?:throw new RuntimeException('FORUM_TEST_INSTALL_DB required'));
    $upgradeName=$driver==='sqlite'?$tmp.'/upgrade.sqlite':(getenv('FORUM_TEST_UPGRADE_DB')?:throw new RuntimeException('FORUM_TEST_UPGRADE_DB required'));
    if($driver==='pgsql'&&$dbName===$upgradeName)throw new RuntimeException('Use separate disposable databases');
    $opts=['db'=>$driver,'db_name'=>$dbName,'db_host'=>getenv('DB_HOST')?:'127.0.0.1','db_port'=>getenv('DB_PORT')?:5432,'db_user'=>getenv('DB_USERNAME')?:'postgres','db_password'=>getenv('DB_PASSWORD')?:'',
        'app_url'=>'http://127.0.0.1:8080','app_name'=>'Community & <test>','category_name'=>'First category','board_name'=>'First board','registration'=>false,
        'admin_email'=>'admin@example.test','admin_name'=>'Administrator','admin_password'=>'InstallerOnlyPass123!','production'=>false];
    $installer=new InstallerService($site);
    rejects(fn()=>$installer->profile($opts+['db_sslmode'=>'bad']),'invalid sslmode','SSL');
    rejects(fn()=>$installer->profile(array_replace($opts,['db'=>'sqlite','db_name'=>$site.'/public/leak.sqlite'])),'reject public SQLite','outside public');
    rejects(fn()=>$installer->profile(array_replace($opts,['db'=>'sqlite','db_name'=>':memory:'])),'reject ephemeral SQLite','persistent');
    rejects(fn()=>$installer->profile(array_replace($opts,['db_port'=>0])),'reject invalid port','порт');
    rejects(fn()=>$installer->profile(array_replace($opts,['app_url'=>'https://example.test/path'])),'reject unsupported base path','URL');
    $token=SetupToken::issue($site);
    rejects(fn()=>$installer->install($opts+['source'=>'web-installer','setup_token'=>str_repeat('0',64)]),'web token required','Setup token');
    // Exercise the actual Dotenv serializer with secrets, including apostrophes.
    $envPath=$tmp.'/test.env';$env=new InstallerEnvironment($envPath);
    $special="p' a#ss\$word\\x\"!";
    $env->write(['DB_PASSWORD'=>$special,'APP_NAME'=>'Forum $ & name'],$site.'/.env.example');
    check($env->read()['DB_PASSWORD']===$special && $env->read()['APP_NAME']==='Forum $ & name','dotenv secrets round-trip');
    check((fileperms($envPath)&0777)===0600,'dotenv private file mode');
    $hash=hash_file('sha256',$envPath);
    rejects(fn()=>$env->write(['DB_PASSWORD'=>"bad\nINJECT=true"],$site.'/.env.example'),'reject multiline environment injection');
    check(hash_file('sha256',$envPath)===$hash,'invalid env preserves previous file');
    if($driver==='pgsql'){
        setenv('DB_DRIVER','pgsql');setenv('DB_DATABASE',$dbName);
        $probe=new DatabaseManager(new ConfigRepository($site));
        check(!$probe->tableExists('ce_users'),'empty disposable install DB');unset($probe);
    }
    // Simulate a process interrupted after seed and before installed.lock.
    $first=$installer->install($opts+['write_lock'=>false]);
    check($first['ok']&&!$installer->installed(),'first installation stage without marker');
    $key=$envValue=(new InstallerEnvironment($site.'/.env'))->read()['APP_KEY'];
    $cfg=new ConfigRepository($site);$db=new DatabaseManager($cfg);$pdo=$db->connection();
    check((int)$pdo->query('SELECT COUNT(*) FROM ce_users')->fetchColumn()===1,'one administrator');
    check($pdo->query('SELECT name FROM ce_forum_boards')->fetchColumn()==='First board','initial board persisted');
    check($pdo->query('SELECT name FROM ce_forum_categories')->fetchColumn()==='First category','initial category persisted');
    check((new ForumDatabaseCheck($db))->check()['ok'],'forum schema and roles ready');
    if($driver==='sqlite')check($pdo->query('PRAGMA journal_mode')->fetchColumn()==='wal'&&(int)$pdo->query('PRAGMA foreign_keys')->fetchColumn()===1,'SQLite WAL and foreign keys');
    $envHash=hash_file('sha256',$site.'/.env');
    rejects(fn()=>$installer->install(array_replace($opts,['admin_password'=>'WrongPass12345!'])),'wrong resume password','administrator');
    check(hash_file('sha256',$site.'/.env')===$envHash,'wrong resume credentials do not modify .env');
    $second=$installer->install($opts+['source'=>'web-installer','setup_token'=>$token]);
    check($second['ok']&&$installer->installed()&&$second['seed']['resumed'],'resume completes without duplicate administrator');
    check(!SetupToken::verify($site,$token),'web token consumed');
    check((new InstallerEnvironment($site.'/.env'))->read()['APP_KEY']===$key,'resume preserves APP_KEY');
    check((int)$pdo->query('SELECT COUNT(*) FROM ce_users')->fetchColumn()===1,'resume user count');
    check((int)$pdo->query("SELECT COUNT(*) FROM ce_audit_log WHERE event='installer.admin_created'")->fetchColumn()===1,'resume does not duplicate creation audit');
    rejects(fn()=>$installer->install($opts+['force'=>true]),'installed lock cannot be overridden','уже установлен');
    check($installer->requirements()['configured_ready'],'installed readiness');
    unset($pdo,$db,$cfg);
    // Recreate the 0.1.1 migration set in a separate, empty DB and retain real forum data.
    setenv('DB_DRIVER',$driver);setenv('DB_DATABASE',$upgradeName);if($driver==='sqlite')setenv('SQLITE_DATABASE',$upgradeName);
    $db=new DatabaseManager(new ConfigRepository($site));$pdo=$db->connection();
    check(!$db->tableExists('ce_users'),'empty disposable upgrade DB');
    $old=$tmp.'/old';mkdir($old);mkdir($old.'/'.$driver);
    foreach(glob($root.'/migrations/'.$driver.'/*.sql') as $file)if(!preg_match('/^001[67]_/',basename($file)))copy($file,$old.'/'.$driver.'/'.basename($file));
    $runner=new MigrationRunner($pdo,$old);$runner->run($driver);
    $roles=new RoleRepository($db);$roles->seedDefaults();$user=(new UserRepository($db,$roles,new PasswordHasher(new ConfigRepository($site))))->createAdmin('old@example.test','Old admin','OldAdminPass123!');
    $thread=Uuid::v4();$post=Uuid::v4();
    $db->transaction(function()use($db,$thread,$post,$user){(new ThreadRepository($db))->insert($thread,'22222222-2222-4222-8222-222222222222',$user,'Existing topic');(new PostRepository($db))->insert($post,$thread,$user,'Existing body',1);});
    $before=$pdo->query('SELECT migration,checksum FROM ce_migrations ORDER BY migration')->fetchAll();
    $runner=new MigrationRunner($pdo,$root.'/migrations');
    check(count($runner->status($driver)['pending'])===2,'two pending migrations from 0.1.1');
    check($runner->run($driver)['applied']===2&&$runner->run($driver)['applied']===0,'upgrade once and idempotent rerun');
    check($pdo->query("SELECT migration,checksum FROM ce_migrations WHERE migration NOT LIKE '0016_%' AND migration NOT LIKE '0017_%' ORDER BY migration")->fetchAll()===$before,'old ledger checksums unchanged');
    check((new PostRepository($db))->inThread($thread,0)[0]['body']==='Existing body','upgrade preserved existing post');
    check((new ThreadRepository($db))->inBoard('22222222-2222-4222-8222-222222222222',0)[0]['id']===$thread,'board listing after upgrade');
    // Missing applied file is not reported as a healthy migration state.
    $runnerOld=new MigrationRunner($pdo,$old);
    check(count($runnerOld->status($driver)['failed'])===2,'missing applied migration reported');
    rejects(fn()=>$runnerOld->run($driver),'missing applied migration blocks execution','missing');
    copy($root.'/migrations/'.$driver.'/0016_forum_lookup_indexes.sql',$old.'/'.$driver.'/0016_forum_lookup_indexes.sql');
    copy($root.'/migrations/'.$driver.'/0017_forum_identity.sql',$old.'/'.$driver.'/0017_forum_identity.sql');
    $bad=$old.'/'.$driver.'/0018_failure_test.sql';
    file_put_contents($bad,'CREATE TABLE forum_rollback_probe(id INTEGER); INSERT INTO nonexistent_forum_probe VALUES (1);');
    rejects(fn()=>$runnerOld->run($driver),'broken SQL migration rolls back');
    check(!$db->tableExists('forum_rollback_probe')&&count($runnerOld->status($driver)['failed'])===1,'failed migration ledger and DDL rollback');
    file_put_contents($bad,'CREATE TABLE forum_rollback_probe(id INTEGER);');
    check($runnerOld->run($driver)['applied']===1&&$db->tableExists('forum_rollback_probe'),'corrected failed migration resumes');
    echo "Forum installer regression ($driver) passed\n";
} finally {unset($pdo,$db,$runner,$runnerOld,$roles);cleanup($tmp);}
