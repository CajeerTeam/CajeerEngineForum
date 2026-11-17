<?php
/** Physical portable backup/restore and fail-closed recovery tests; no service dependencies. */
declare(strict_types=1);
use CajeerEngine\Backup\{BackupManager,BackupPolicy,ColdRecovery,DatabaseBackup,OffsiteBackup,RecoveryBundle};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as F;
require dirname(__DIR__).'/core/Console/NativeCli.php';
function check(bool $ok,string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
function reject(callable $fn,string $name,?string $reason=null): void {try{$fn();}catch(Throwable $e){if($reason!==null&&!str_contains($e->getMessage(),$reason))throw $e;check(true,$name);return;}throw new RuntimeException('Accepted: '.$name);}
$tool=dirname(__DIR__); $work=sys_get_temp_dir().'/ce-cold-'.bin2hex(random_bytes(12)); F::dir($work);
try {
    $source=$work.'/lost-host'; F::dir($source.'/storage/database'); F::dir($source.'/config');
    $db=$source.'/storage/database/old-name.sqlite';
    foreach (['/usr/bin/python3','/usr/local/bin/python3'] as $p) if (is_executable($p)) { putenv('BACKUP_SQLITE_PYTHON='.$p); break; }
    putenv('DB_DRIVER=sqlite'); putenv('SQLITE_DATABASE='.$db); putenv('QUEUE_DRIVER=file'); putenv('STORAGE_DRIVER=local'); putenv('STORAGE_LOCAL_PATH=storage/app');
    foreach (['database','storage','queue'] as $name) F::copy($tool.'/config/'.$name.'.php',$source.'/config/'.$name.'.php');
    foreach (['release.json','VERSION','.env.example'] as $name) F::copy($tool.'/'.$name,$source.'/'.$name);
    DatabaseBackup::run([getenv('BACKUP_SQLITE_PYTHON'),'-I','-c',<<<'PY'
import pathlib,sqlite3,sys
with sqlite3.connect(sys.argv[1]) as c:
    c.execute('PRAGMA foreign_keys=ON')
    for p in sorted(pathlib.Path(sys.argv[2]).glob('*.sql')): c.executescript(p.read_text())
    c.execute("INSERT INTO ce_users(id,email,name,password_hash) VALUES('12345678-1234-1234-1234-123456789abc','recovery@example.invalid','Recovery Member','test')")
    board=c.execute('SELECT id FROM ce_forum_boards LIMIT 1').fetchone()[0]
    c.execute("INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES('12345678-1234-1234-1234-123456789abd',?,'12345678-1234-1234-1234-123456789abc','Recovery Marker')",(board,))
    c.execute("INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES('12345678-1234-1234-1234-123456789abe','12345678-1234-1234-1234-123456789abd','12345678-1234-1234-1234-123456789abc','Original recovered text',1,1)")
PY,$db,$tool.'/migrations/sqlite']);
    F::write($source.'/.env',"DB_DRIVER=sqlite\nSQLITE_DATABASE=$db\nAPP_KEY=private-recovery-key\nQUEUE_DRIVER=file\nSTORAGE_DRIVER=local\n");
    F::write($source.'/storage/app/installed.lock','{}'); F::write($source.'/storage/queue/pending/default/job.json','pending');
    F::write($source.'/public/uploads/original.bin',random_bytes(2*1048576+11)); $media=F::hash($source.'/public/uploads/original.bin');
    F::write($work.'/key',base64_encode(random_bytes(32))); putenv('BACKUP_KEY_FILE='.$work.'/key');
    $manager=new BackupManager($source,new ConfigRepository($source)); $id=$manager->create()['id']; $bundle=$work.'/offsite.cebackup';
    $export=$manager->exportBundle($id,$bundle); $sha=$export['bundle_sha256'];
    F::dir($work.'/remote'); $policy=BackupPolicy::parse(['namespace'=>'cold-ci','require_separate_device'=>false,'destination'=>['type'=>'file','directory'=>$work.'/remote']]);
    $off=new OffsiteBackup($source,$policy); $off->publish($id,$sha,$bundle,filesize($bundle));
    RecoveryBundle::cleanup($source); F::remove($bundle); check(!file_exists($source),'complete source loss');
    $fetch=(new OffsiteBackup($tool,$policy,true))->fetch($id,$sha,$work.'/fetched.cebackup');
    $bundle=$work.'/fetched.cebackup'; check($fetch['bundle_sha256']===$sha&&F::hash($bundle)===$sha&&(fileperms($bundle)&0777)===0600,'off-site fetch on another host, exact digest and mode');
    reject(fn()=>(new OffsiteBackup($tool,$policy,true))->fetch($id,$sha,$bundle),'fetch never overwrites');
    $remote=$work.'/remote/cold-ci/'.$id.'-'.$sha.'.cebackup'; F::write($remote,'corrupt');
    reject(fn()=>(new OffsiteBackup($tool,$policy,true))->fetch($id,$sha,$work.'/corrupt.cebackup'),'corrupt off-site object not published','checksum');
    check(!file_exists($work.'/corrupt.cebackup'),'no invalid fetched bundle');
    // Actual authenticated portable verification and filesystem/database materialization.
    $stage=$work.'/snapshot'; F::dir($stage); $key=base64_decode(trim(file_get_contents($work.'/key')),true); RecoveryBundle::extract($bundle,$stage,$key); sodium_memzero($key);
    $config=new ConfigRepository($tool); $config->set('database.default','sqlite'); $config->set('database.connections.sqlite.database','storage/database/cajeer.sqlite');
    $portable=new BackupManager($tool,$config); $snap=$portable->portableSnapshot($stage);
    check($snap['manifest']['database']['database']===$db&&str_contains($snap['environment'],'private-recovery-key'),'old DB path authenticated but does not bind new host');
    $site=$work.'/different-root'; F::dir($site); (new ColdRecovery($tool))->restoreFiles($stage,$site,$snap['manifest']);
    check(F::hash($site.'/public/uploads/original.bin')===$media&&file_get_contents($site.'/storage/queue/pending/default/job.json')==='pending'&&is_file($site.'/storage/app/maintenance.json'),'real files, queue and maintenance recovered');
    $dest=new DatabaseBackup($site,$config); $dest->restoreFresh($stage.'/database.dump');
    $restored=$site.'/storage/database/cajeer.sqlite';
    DatabaseBackup::run([getenv('BACKUP_SQLITE_PYTHON'),'-I','-c',<<<'PY'
import sqlite3,sys
with sqlite3.connect(sys.argv[1]) as c:
    assert c.execute("SELECT body FROM ce_forum_posts WHERE id='12345678-1234-1234-1234-123456789abe'").fetchone()[0]=='Original recovered text'
    assert c.execute("SELECT name FROM ce_users WHERE email='recovery@example.invalid'").fetchone()[0]=='Recovery Member'
    assert c.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
    assert c.execute('PRAGMA foreign_key_check').fetchone() is None
    assert c.execute("SELECT count(*) FROM ce_forum_fts_posts WHERE ce_forum_fts_posts MATCH 'recovered'").fetchone()[0]==1
PY,$restored]);
    check(true,'actual SQLite forum tables, FTS and foreign keys restored at a different path');
    reject(fn()=>$dest->restoreFresh($stage.'/database.dump'),'fresh restore refuses existing DB');
    F::write($stage.'/files/public/uploads/original.bin','changed'); reject(fn()=>$portable->portableSnapshot($stage),'authenticated file inventory detects corruption');
    F::json($work.'/profile.json',['APP_URL'=>'https://recovered.example.invalid']);
    $recovery=new ColdRecovery($tool); $target=$work.'/new-forum';
    // This fixture deliberately has no vendor/application; a genuine restored runtime must pass the probe.
    reject(fn()=>$recovery->run($bundle,$work.'/key',$target,$work.'/profile.json'),'incomplete runtime not published','vendor dependencies');
    check(!file_exists($target)&&is_file($work.'/.new-forum.recovery/site/storage/app/maintenance.json'),'failure remains private and closed');
    reject(fn()=>$recovery->run($bundle,$work.'/key',$target,$work.'/profile.json'),'same recovery safely restarts','vendor dependencies');
    F::json($work.'/profile-other.json',['APP_URL'=>'https://other.example.invalid']);
    reject(fn()=>$recovery->run($bundle,$work.'/key',$target,$work.'/profile-other.json'),'changed profile cannot resume','original bundle');
    F::write($work.'/wrong-key',base64_encode(random_bytes(32))); reject(fn()=>$recovery->run($bundle,$work.'/wrong-key',$work.'/wrong',$work.'/profile.json'),'wrong key cannot publish');
    check(!file_exists($work.'/wrong'),'wrong-key target absent');
    F::dir($work.'/foreign'); F::write($work.'/foreign/sentinel','keep');
    reject(fn()=>$recovery->run($bundle,$work.'/key',$work.'/foreign',$work.'/profile.json'),'foreign target refused','already exists');
    check(file_get_contents($work.'/foreign/sentinel')==='keep','unrelated installation unchanged');
    F::dir($work.'/.occupied.recovery'); F::write($work.'/.occupied.recovery/sentinel','keep');
    reject(fn()=>$recovery->run($bundle,$work.'/key',$work.'/occupied',$work.'/profile.json'),'unrelated workspace refused','unrelated files');
    check(file_get_contents($work.'/.occupied.recovery/sentinel')==='keep','unrelated workspace unchanged');
    // Invoke the real, private recovery probe with a disposable PHP fixture.
    // The trusted operator must preload its corrected FTS class for PostgreSQL
    // 0.8.7–0.9.9, but never for a SQLite or current-version recovery.
    $probeRoot=$work.'/trusted-probe-tool';
    F::dir($probeRoot.'/tools'); F::dir($probeRoot.'/core/Forum/Service');
    F::copy($tool.'/core/Forum/Service/ForumSearchMaintenance.php',$probeRoot.'/core/Forum/Service/ForumSearchMaintenance.php');
    F::write($probeRoot.'/tools/recovery-probe.php',
        '<?php echo json_encode(["ok"=>true,"preloaded"=>class_exists(\CajeerEngine\Forum\Service\ForumSearchMaintenance::class,false)]);');
    $probeSite=$work.'/trusted-probe-site'; F::dir($probeSite);
    F::write($probeSite.'/VERSION','1.3.0');
    $identity=['edition'=>'forum','version'=>'1.3.0','upstream_version'=>'1.3.0','edition_version'=>'0.9.9'];
    F::json($probeSite.'/release.json',$identity);
    $method=new ReflectionMethod(ColdRecovery::class,'probe');
    $instance=new ColdRecovery($probeRoot);
    foreach (['0.8.7','0.9.9'] as $historical) {
        $identity['edition_version']=$historical; F::json($probeSite.'/release.json',$identity);
        $pgCheck=$method->invoke($instance,$probeSite,$work,$work.'/key','pgsql');
        check(($pgCheck['preloaded']??null)===true,'historical PostgreSQL '.$historical.' cold probe loads trusted compatibility');
    }
    $sqliteCheck=$method->invoke($instance,$probeSite,$work,$work.'/key','sqlite');
    check(($sqliteCheck['preloaded']??null)===false,'historical SQLite cold probe remains unmodified');
    $identity['edition_version']='1.0.0'; F::json($probeSite.'/release.json',$identity);
    $currentCheck=$method->invoke($instance,$probeSite,$work,$work.'/key','pgsql');
    check(($currentCheck['preloaded']??null)===false,'current PostgreSQL cold probe uses current code');
    echo "Portable cold recovery regression passed\n";
} finally { RecoveryBundle::cleanup($work); }
