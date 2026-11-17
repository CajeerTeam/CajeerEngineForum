<?php
declare(strict_types=1);
/** Live filesystem backup discovery without the local catalog (S3 tested in physical CI). */
require dirname(__DIR__).'/core/Console/NativeCli.php';
use CajeerEngine\Backup\OffsiteBackup;
use CajeerEngine\Backup\BackupPolicy;
use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Update\OperationFiles as F;
function guard(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
function denied(callable $test): void { try { $test(); } catch (Throwable) { return; } throw new RuntimeException('Unsafe discovery input accepted'); }
$base=sys_get_temp_dir().'/forum-discovery-'.bin2hex(random_bytes(10));F::dir($base);
try {
    F::dir($base.'/host/storage');F::dir($base.'/remote'); F::dir($base.'/remote/dr-fixture');
    chmod($base.'/remote',0700);chmod($base.'/remote/dr-fixture',0700);
    $policy=BackupPolicy::parse(['namespace'=>'dr-fixture','destination'=>['type'=>'file','directory'=>$base.'/remote'],'require_separate_device'=>false]);
    $offsite=new OffsiteBackup($base.'/host',$policy,true);
    $sha=str_repeat('a',64);
    $ids=[];
    foreach ([1,2,3,4,5] as $i) {
        $id='20261117-060000-'.str_pad(dechex($i),16,'0',STR_PAD_LEFT);
        $ids[]=$id;
        F::write($base.'/remote/dr-fixture/'.$id.'-'.$sha.'.cebackup','invalid ciphertext');
    }
    F::write($base.'/remote/dr-fixture/misleading.cebackup','bad');
    symlink('/etc/passwd',$base.'/remote/dr-fixture/20261117-060000-'.str_repeat('b',16).'-'.$sha.'.cebackup');
    $first=$offsite->discover(2);guard($first['ok']===true&&count($first['objects'])===2&&$first['next_cursor']!==null,'Page one failed');
    guard(array_column($first['objects'],'id')===array_slice($ids,0,2),'First page ordering failed');
    $second=$offsite->discover(2,$first['next_cursor']);
    guard(array_column($second['objects'],'id')===array_slice($ids,2,2),'Second page ordering failed');
    $third=$offsite->discover(2,$second['next_cursor']);
    guard(array_column($third['objects'],'id')===array_slice($ids,4,1),'Third page ordering failed');
    guard($third['next_cursor']===null,'Extra page not needed');
    guard($first['authenticated']===false&&$first['objects'][0]['authenticated']===false,'Unverified object falsely authenticated');
    denied(fn()=>$offsite->discover(0));denied(fn()=>$offsite->discover(201));
    denied(fn()=>$offsite->discover(1,'../../etc/passwd'));
    F::dir($base.'/private');chmod($base.'/private',0700);
    denied(fn()=>$offsite->fetch($ids[0],$sha,$base.'/private/snapshot.cebackup'));
    guard(!file_exists($base.'/private/snapshot.cebackup'),'Unverified object published to recovery directory');
    echo "PASS independent offsite discovery: pagination, unsafe filename/cursor, no false authentication or corrupted fetch\n";
} finally {RecoveryBundle::cleanup($base);}
