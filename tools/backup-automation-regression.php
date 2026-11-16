<?php
declare(strict_types=1);
use CajeerEngine\Backup\{AutomatedBackup,BackupManager,BackupPolicy,DatabaseBackup,OffsiteBackup,RecoveryBundle};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Update\OperationLock;
require dirname(__DIR__).'/core/Console/NativeCli.php';
function check(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message);echo "PASS $message\n";}
function reject(callable $fn,string $message): void {try {$fn();} catch (Throwable) {check(true,$message);return;} throw new RuntimeException('Accepted: '.$message);}
$source=dirname(__DIR__);$work=sys_get_temp_dir().'/ce-auto-backup-'.bin2hex(random_bytes(8));F::dir($work);
try {
    $root=$work.'/site';$off=$work.'/offsite';F::dir($off);F::dir($root.'/storage/database');F::dir($root.'/config');
    putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE=storage/database/cajeer.sqlite');putenv('STORAGE_DRIVER=local');putenv('STORAGE_LOCAL_PATH=storage/app');putenv('QUEUE_DRIVER=file');
    foreach (['/usr/bin/python3','/usr/local/bin/python3'] as $python) if (is_executable($python)) {putenv('BACKUP_SQLITE_PYTHON='.$python);break;}
    $db=$root.'/storage/database/cajeer.sqlite';
    DatabaseBackup::run([getenv('BACKUP_SQLITE_PYTHON'),'-I','-c',<<<'PY'
import sqlite3,sys,pathlib
c=sqlite3.connect(sys.argv[1]); c.execute('PRAGMA foreign_keys=ON')
for p in sorted(pathlib.Path(sys.argv[2]).glob('*.sql')): c.executescript(p.read_text())
c.execute("INSERT INTO ce_users(id,email,name,password_hash) VALUES('12345678-1234-1234-1234-123456789abc','backup@example.invalid','Backup member','test')")
c.commit();c.close()
PY,$db,$source.'/migrations/sqlite']);
    foreach (['database','storage','queue'] as $name) F::copy($source.'/config/'.$name.'.php',$root.'/config/'.$name.'.php');
    F::copy($source.'/release.json',$root.'/release.json');F::copy($source.'/VERSION',$root.'/VERSION');
    F::write($root.'/.env',"APP_KEY=private-backup-secret\n");F::write($root.'/storage/app/installed.lock','{}');F::write($work.'/key',base64_encode(random_bytes(32)));putenv('BACKUP_KEY_FILE='.$work.'/key');
    F::write($root.'/storage/app/content/entry.json','before');F::write($root.'/storage/queue/job.json','queued');
    // A real multi-chunk file, also forces multipart upload in the optional S3 acceptance.
    F::write($root.'/public/uploads/media.bin',random_bytes(in_array('--s3',$argv,true)?9*1048576:2*1048576+9));
    $config=new ConfigRepository($root);$manager=new BackupManager($root,$config);
    $base=$root.'/storage/app/backups/automation';$catalog=$base.'/state.json';
    $policyData=['namespace'=>'backup-ci','interval_seconds'=>300,'lock_wait_seconds'=>0,'daily'=>1,'weekly'=>0,'monthly'=>0,'min_free_bytes'=>0,'require_separate_device'=>false,'prune_offsite'=>true,'destination'=>['type'=>'file','directory'=>$off]];
    if (in_array('--s3',$argv,true)) {
        $policyData['destination']=['type'=>'s3','bucket'=>(string)getenv('CE_BACKUP_TEST_BUCKET'),'prefix'=>'automation','region'=>'us-east-1','endpoint'=>(string)getenv('CE_BACKUP_TEST_ENDPOINT')];
        $p=BackupPolicy::parse($policyData);$a=new AutomatedBackup($root,$config,$p);
        $first=$a->run(true);check($first['ok'],'S3 multipart upload and streaming readback');
        $second=$a->run(true);$third=$a->run(true);check($second['ok']&&$third['ok'],'S3 repeat capture and rotation');
        $records=F::read($catalog)['records'];$old=$records[$first['latest_local_id']];check($old['state']==='retired'&&!is_dir($manager->directory($first['latest_local_id'])),'S3 exact object retirement and local cleanup');
        $dest=new OffsiteBackup($root,$p);reject(fn()=>$dest->verify($first['latest_local_id'],$old['sha256'],$old['bytes']),'S3 retired object not readable');
        $r=$records[$third['latest_local_id']];$dest->verify($third['latest_local_id'],$r['sha256'],$r['bytes']);check(true,'S3 latest object protected and readable');
        $download=$work.'/download.cebackup'; $fetched=(new OffsiteBackup($root,$p,true))->fetch($third['latest_local_id'],$r['sha256'],$download);
        check($fetched['bytes']===$r['bytes']&&F::hash($download)===$r['sha256'],'S3 recovery download verifies encrypted object bytes');
        reject(fn()=>(new OffsiteBackup($root,$p,true))->fetch($third['latest_local_id'],$r['sha256'],$download),'S3 recovery cannot overwrite local input');
        RecoveryBundle::cleanup($work); exit(0);
    }
    $p=BackupPolicy::parse($policyData);$run=static fn(bool $force=false)=>(new AutomatedBackup($root,$config,$p))->run($force);
    F::json($work.'/policy.json',$policyData);check(BackupPolicy::load($root,$work.'/policy.json')->identity()===$p->identity(),'external private policy accepted');
    chmod($work.'/policy.json',0644);reject(fn()=>BackupPolicy::load($root,$work.'/policy.json'),'world-readable policy rejected');chmod($work.'/policy.json',0600);
    reject(fn()=>new OffsiteBackup($root,BackupPolicy::parse(array_replace($policyData,['require_separate_device'=>true]))),'same-device fallback rejected by default');
    reject(fn()=>BackupPolicy::parse(array_replace($policyData,['daily'=>0])),'retention cannot disable all daily copies');
    reject(fn()=>BackupPolicy::parse(array_replace($policyData,['destination'=>['type'=>'s3','bucket'=>'test-bucket','prefix'=>'../bad','endpoint'=>'http://host']])),'unsafe S3 prefix/endpoint rejected');
    $manual=$manager->create()['id'];$first=$run();$id1=$first['latest_local_id'];
    check($manager->verify($manual)['automation']===null&&$manager->verify($id1)['automation']===$p->identity(),'authenticated manifest identifies only automatic recovery sets');
    check($first['ok']&&$first['pending']===0&&$first['latest_offsite_id']===$id1,'scheduled capture + verified encrypted external copy');
    $state=F::read($catalog);$r1=$state['records'][$id1];$bundle=fn($id,$r)=>$off.'/backup-ci/'.$id.'-'.$r['sha256'].'.cebackup';$file1=$bundle($id1,$r1);
    check(is_file($file1)&&F::hash($file1)===$r1['sha256']&&fileperms($file1)%512===0600&&!str_contains(file_get_contents($file1),'private-backup-secret'),'encrypted bytes, private mode and digest');
    check($run()['latest_local_id']===$id1&&count(F::read($catalog)['records'])===1,'interval prevents duplicate snapshot');
    $manager->drill($file1,$work.'/drill',300,3600);check(!is_dir($work.'/drill'),'real bundle decrypted and database/files restored in isolated drill');
    // Off-site outage must not prevent a new local snapshot, nor authorize pruning.
    rename($off,$off.'-offline');reject(fn()=>$run(true),'off-site outage fails with pending local snapshot');
    $report=AutomatedBackup::status($root);$id2=$report['latest_local_id'];check($report['pending']===1&&!$report['ok']&&$id2!==$id1&&is_dir($manager->directory($id2)),'local recovery remains available during outage');
    rename($off.'-offline',$off);
    F::json($root.'/storage/app/maintenance.json',['enabled'=>true]);reject(fn()=>$run(true),'failed due capture does not starve pending off-site delivery');F::remove($root.'/storage/app/maintenance.json');
    check(AutomatedBackup::status($root)['latest_offsite_id']===$id2&&AutomatedBackup::status($root)['pending']===0&&$run()['ok'],'retry sends original local snapshot without recapture');
    $r2=F::read($catalog)['records'][$id2];$file2=$bundle($id2,$r2);check(is_file($file2),'second encrypted bundle published');
    // Simulate kill after successful remote publication but before durable receipt.
    $journal=F::read($catalog);$journal['records'][$id2]['state']='pending';F::copy($file2,$base.'/'.$id2.'.cebackup');F::json($catalog,$journal);
    check($run()['ok']&&F::hash($file2)===$r2['sha256']&&!is_file($base.'/'.$id2.'.cebackup'),'retry of published ciphertext is idempotent after receipt loss');
    // Corrupt oldest remote object. A new verified copy exists, but the old local set must not be pruned.
    $original=F::hash($file1);$saved=$work.'/saved';F::copy($file1,$saved);F::write($file1,'corrupt');
    reject(fn()=>$run(true),'rotation refuses to delete local copy with corrupted off-site copy');
    $id3=AutomatedBackup::status($root)['latest_local_id'];check(is_dir($manager->directory($id1))&&is_dir($manager->directory($manual))&&$id3!==$id2,'corruption preserves old and manual recovery sets');
    F::copy($saved,$file1);check(F::hash($file1)===$original,'repair test remote object');
    $report=$run();check($report['ok']&&!is_dir($manager->directory($id1))&&!is_file($file1)&&is_dir($manager->directory($manual)),'GFS rotation removes only owned old local and remote copy');
    check(is_dir($manager->directory($id2))&&is_dir($manager->directory($id3)),'two newest verified copies protected');
    // Keep a manual backup; remote pruning disabled retains the expired remote object.
    $keepRemote=BackupPolicy::parse(array_replace($policyData,['prune_offsite'=>false]));$id4=(new AutomatedBackup($root,$config,$keepRemote))->run(true)['latest_local_id'];
    check(!is_dir($manager->directory($id2))&&is_file($file2)&&is_dir($manager->directory($id4)),'local rotation can retain all off-site objects');
    // Existing application activity, maintenance, and an unfinished import must block new capture.
    $lease=OperationLock::acquire($root,false);try {reject(fn()=>$run(true),'exclusive capture refuses concurrent activity');} finally {OperationLock::release($lease);}
    F::json($root.'/storage/app/maintenance.json',['enabled'=>true]);reject(fn()=>$run(true),'maintenance defers capture');F::remove($root.'/storage/app/maintenance.json');
    $sql=static fn(string $q)=>DatabaseBackup::run([getenv('BACKUP_SQLITE_PYTHON'),'-I','-c','import sqlite3,sys;c=sqlite3.connect(sys.argv[1]);c.executescript(sys.argv[2]);c.commit();c.close()',$db,$q]);
    $sql("INSERT INTO ce_forum_import_jobs(id,source_version,category_id,backup_id,plan_json,created_at,updated_at) VALUES('import','3.3','category','backup','{}',1,1)");reject(fn()=>$run(true),'incomplete import blocks capture even without maintenance marker');$sql('DELETE FROM ce_forum_import_jobs');
    $bad=BackupPolicy::parse(array_replace($policyData,['namespace'=>'other']));reject(fn()=>(new AutomatedBackup($root,$config,$bad))->run(true),'destination change fails closed before deleting/capturing');
    check($run()['ok'],'valid schedule recovers after deferred attempts');
    $data=F::read($catalog);$r=$data['records'][$id4];$file4=$bundle($id4,$r);$dir=$manager->directory($id4);RecoveryBundle::cleanup($dir);
    check($manager->importBundle($file4)['ok']&&$manager->verify($id4)['ok'],'real off-site bundle imports into verified local recovery set');
    $lease=OperationLock::acquire($root,true);try {$manager->restoreCaptured($id4);} finally {OperationLock::release($lease);}
    check(file_get_contents($root.'/storage/app/content/entry.json')==='before'&&file_get_contents($root.'/storage/queue/job.json')==='queued','restored actual file and queue payload');
    $marker=$root.'/storage/logs/observability/backup-automation.json';$m=F::read($marker);$m['checked_at']=time()-7200;F::json($marker,$m);check(!AutomatedBackup::status($root)['ok']&&AutomatedBackup::status($root)['stale'],'stopped timer becomes unhealthy');
    // UTC calendar bucket selection and fixed two-copy floor independent of daily count.
    $history=[];foreach (['2025-01-01','2026-04-01','2026-09-01','2026-11-02','2026-11-14','2026-11-15','2026-11-16'] as $i=>$date) $history['id'.$i]=['state'=>'verified','created_at'=>strtotime($date.'T12:00:00Z')];
    $gfs=BackupPolicy::parse(array_replace($policyData,['daily'=>2,'weekly'=>4,'monthly'=>3]));$keep=$gfs->keep($history,strtotime('2026-11-16T13:00:00Z'));
    check(!isset($keep['id0'])&&!isset($keep['id1'])&&!isset($keep['id4'])&&isset($keep['id2'],$keep['id3'],$keep['id5'],$keep['id6']),'daily weekly monthly UTC buckets');
    echo "Automated backup regression passed\n";
} finally {RecoveryBundle::cleanup($work);}
