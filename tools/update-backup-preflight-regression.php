<?php
declare(strict_types=1);
use CajeerEngine\Backup\{BackupManager,DatabaseBackup,RecoveryBundle};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\{OperationFiles as F,OperationLock,UpdatePreflight};
require dirname(__DIR__).'/core/Console/NativeCli.php';
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function reject(callable $fn,string $message): void { try {$fn();} catch (Throwable $e) {check(true,$message);return;} throw new RuntimeException('Accepted: '.$message); }
$source=dirname(__DIR__); $work=sys_get_temp_dir().'/ce-update-proof-'.bin2hex(random_bytes(12)); F::dir($work);
try {
    $root=$work.'/site'; $off=$work.'/offsite'; F::dir($root.'/config'); F::dir($root.'/storage/database'); F::dir($off);
    foreach (['database','storage','queue'] as $name) F::copy($source.'/config/'.$name.'.php',$root.'/config/'.$name.'.php');
    F::copy($source.'/release.json',$root.'/release.json'); F::copy($source.'/VERSION',$root.'/VERSION');
    foreach (['DB_DRIVER=sqlite','SQLITE_DATABASE=storage/database/test.sqlite','STORAGE_DRIVER=local','STORAGE_LOCAL_PATH=storage/app','QUEUE_DRIVER=file','UPDATE_BACKUP_OFFSITE_POLICY_FILE='] as $v) putenv($v);
    $python=is_executable('/usr/bin/python3')?'/usr/bin/python3':'/usr/local/bin/python3'; putenv('BACKUP_SQLITE_PYTHON='.$python);
    $db=$root.'/storage/database/test.sqlite';
    DatabaseBackup::run([$python,'-I','-c','import sqlite3,sys; c=sqlite3.connect(sys.argv[1]); c.execute("CREATE TABLE fixture(id INTEGER PRIMARY KEY, v TEXT)"); c.execute("INSERT INTO fixture VALUES(1, ?)",( "before",)); c.commit(); c.close()',$db]);
    F::write($root.'/.env',"APP_KEY=secret-update-fixture\n"); F::write($root.'/storage/app/installed.lock','{}'); F::write($root.'/public/uploads/test.txt','media-before');
    $key=random_bytes(32); F::write($work.'/key',base64_encode($key)); putenv('BACKUP_KEY_FILE='.$work.'/key');
    $config=new ConfigRepository($root); $backup=new BackupManager($root,$config);
    $lease=OperationLock::acquire($root,true);
    try {
        check($backup->updatePreflight()>0,'preflight estimates real snapshot and recovery copies');
        $id=$backup->capture()['id']; $before=F::hash($db); $proof=$backup->proveUpdateRecovery($id);
        check($proof['ok']&&$proof['database']['tables_read']===1&&$proof['environment_restored']&&$proof['files_restored']>0,'exact SQLite snapshot, configuration and files restored');
        check(F::hash($db)===$before&&file_get_contents($root.'/public/uploads/test.txt')==='media-before','rehearsal does not modify live data');
        check($proof['manifest_sha256']===$backup->verify($id)['manifest_sha256']&&$proof['offsite']===null,'proof bound to authenticated backup');
        check(glob(dirname($backup->directory($id)).'/rehearsal-*')===[],'private rehearsal copies cleaned');
        $policy=['namespace'=>'update-ci','require_separate_device'=>false,'min_free_bytes'=>0,'destination'=>['type'=>'file','directory'=>$off]];
        F::json($work.'/policy.json',$policy); putenv('UPDATE_BACKUP_OFFSITE_POLICY_FILE='.$work.'/policy.json');
        check($backup->updatePreflight()>0,'offsite spool included in preflight');
        $proof=$backup->proveUpdateRecovery($id); $receipt=$proof['offsite']; $bundle=$off.'/update-ci/'.$id.'-'.$receipt['sha256'].'.cebackup';
        check($receipt['verified']&&F::hash($bundle)===$receipt['sha256']&&!str_contains(file_get_contents($bundle),'secret-update-fixture'),'encrypted offsite readback verified');
        RecoveryBundle::extract($bundle,$work.'/unpacked',$key);
        check(F::hash($work.'/unpacked/database.dump')===F::hash($backup->directory($id).'/database.dump'),'offsite bundle actually decrypts to exact dump');
        rename($off,$off.'-offline'); reject(fn()=>$backup->proveUpdateRecovery($id),'configured offsite outage blocks admission'); rename($off.'-offline',$off);
        check(glob(dirname($backup->directory($id)).'/rehearsal-*')===[],'failure cleans private rehearsal');
        putenv('UPDATE_BACKUP_OFFSITE_POLICY_FILE=');
        F::write($backup->directory($id).'/database.dump','tampered'); reject(fn()=>$backup->proveUpdateRecovery($id),'corrupt snapshot blocks admission');
        F::remove($root.'/.env'); reject(fn()=>$backup->updatePreflight(),'missing environment blocks preflight');
        UpdatePreflight::writablePlan($root,['files'=>['public/uploads/test.txt','new/folder/file.php'],'removed_files'=>[]]); check(true,'writable plan accepts valid files');
        reject(fn()=>UpdatePreflight::writablePlan($root,['files'=>['public/uploads'],'removed_files'=>[]]),'directory collision blocks deployment');
        putenv('DB_DRIVER=mysql'); reject(fn()=>(new DatabaseBackup($root,new ConfigRepository($root)))->assertDrillReady(),'unisolated MySQL drill blocked');
    } finally {OperationLock::release($lease);}
} finally {RecoveryBundle::cleanup($work);}
