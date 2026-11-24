<?php
declare(strict_types=1);
use CajeerEngine\Update\{ServiceLifecycle,OperationLock,OperationFiles};
use CajeerEngine\Observability\ProbeEndpoint;
require dirname(__DIR__).'/core/Console/NativeCli.php';
$count=0;
function check(bool $ok,string $message): void { global $count; if (!$ok) throw new RuntimeException($message); ++$count; echo "PASS $message\n"; }
function rejects(callable $fn,string $message): void { try{$fn();}catch(Throwable){check(true,$message);return;}throw new RuntimeException('Accepted: '.$message); }
$policy=['schema'=>1,'php_fpm'=>'php8.4-fpm.service','workers'=>['cajeerengine-worker.service','cajeerengine-worker-2.service'],'probe_port'=>8081,'probe_host'=>'localhost'];
$timer='cajeerengine-scheduler.timer';$scheduler='cajeerengine-scheduler.service';$fpm=$policy['php_fpm'];$workers=$policy['workers'];
$states=array_fill_keys([$timer,$fpm,...$workers],'active');$states[$scheduler]='inactive';$events=[];$fail=null;$version='1.5.7';$ready=503;
$cmd=static function(array $args)use(&$states,&$events,&$fail):string {
    $verb=$args[0];$unit=end($args);
    if ($verb==='show')return "LoadState=loaded\nActiveState=".$states[$unit]."\n";
    $events[]="$verb:$unit";
    if ($fail==="$verb:$unit")throw new RuntimeException('simulated systemd failure');
    $states[$unit]=$verb==='start'?'active':'inactive';return '';
};
$probe=static function(string $path)use(&$events,&$version,&$ready):array { $events[]=$path;return $path==='/health/live'?[200,['status'=>'alive','edition_version'=>$version]]:[$ready,['status'=>$ready===503?'not_ready':'ready']]; };
$l=new ServiceLifecycle($policy,$cmd,$probe);
$l->preflight('1.5.7');check($events===['/health/live','/health/ready'],'preflight checks actual unit states and protected HTTP');
$events=[];$l->stop();check($events===array_map(fn($u)=>'stop:'.$u,[$timer,$scheduler,...$workers,$fpm]),'scheduler timer and writers stop before FPM');
check(count(array_filter($states,fn($s)=>$s!=='inactive'))===0,'all declared services stopped');
$events=[];$l->start('1.5.7');check($events===['start:'.$fpm,'/health/live','/health/ready',...array_map(fn($u)=>'start:'.$u,[...$workers,$timer])],'fresh FPM verified before worker and timer startup');
check($states[$scheduler]==='inactive','scheduler oneshot not invoked under exclusive lease');
$l->assertIdentity($l->identity());check(true,'durable service identity accepted');
rejects(fn()=>$l->assertIdentity([]),'changed service identity rejected');
foreach ([['php_fpm'=>'sshd.service'],['workers'=>['evil.service']],['workers'=>['cajeerengine-worker.service','cajeerengine-worker.service']],['probe_host'=>"localhost\r\nX: evil"],['probe_port'=>0]] as $override) rejects(fn()=>new ServiceLifecycle(array_replace($policy,$override),$cmd,$probe),'unsafe service policy rejected');
$events=[];$fail='stop:'.$workers[0];rejects(fn()=>$l->stop(),'stop failure blocks deployment');check(!in_array('stop:'.$fpm,$events,true),'failed drain cannot reach FPM shutdown');
$fail=null;$l->stop();$events=[];$fail='start:'.$fpm;rejects(fn()=>$l->start('1.5.7'),'FPM startup failure blocks activation');check($events===['start:'.$fpm],'writers remain stopped on failed FPM start');
$fail=null;$version='1.5.6';$events=[];rejects(fn()=>$l->start('1.5.7'),'wrong edition fails HTTP gate');check(!in_array('start:'.$workers[0],$events,true),'wrong version cannot start writers');
$version='1.5.7';$ready=200;$events=[];rejects(fn()=>$l->start('1.5.7'),'public readiness during maintenance fails closed');
$ready=503;$l->stop();$version='1.5.6';$l->start('1.5.6');check($states[$fpm]==='active'&&$states[$timer]==='active','previous version services can be reactivated');
$root=dirname(__DIR__);[$code,,$raw]=ProbeEndpoint::response($root,'/health/live','GET',null);check($code===200&&json_decode($raw,true)['edition_version']==='1.5.7','real liveness endpoint exposes release identity');
putenv('UPDATE_SERVICE_POLICY_FILE=/tmp/unsafe.json');rejects(fn()=>ServiceLifecycle::configured(),'external policy must be canonical root-owned /etc path');putenv('UPDATE_SERVICE_POLICY_FILE');check(ServiceLifecycle::configured()===null,'legacy manual deployment remains available without service policy');
// Real flock across independent PHP processes: a running job cannot be replaced.
$dir=sys_get_temp_dir().'/ce-lifecycle-'.bin2hex(random_bytes(8));OperationFiles::dir($dir.'/storage/app');
$lease=OperationLock::acquire($dir,true);
$native=$root.'/core/Console/NativeCli.php';$script='require $argv[1]; $l=CajeerEngine\\Update\\OperationLock::acquire($argv[2],false,true); echo "admitted"; CajeerEngine\\Update\\OperationLock::release($l);';
$process=proc_open([PHP_BINARY,'-r',$script,$native,$dir],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
try {stream_set_blocking($pipes[1],false);usleep(150000);check(stream_get_contents($pipes[1])===''&&proc_get_status($process)['running'],'new worker waits behind exclusive update lease');OperationLock::release($lease);$lease=null;stream_set_blocking($pipes[1],true);stream_set_timeout($pipes[1],3);check(stream_get_contents($pipes[1])==='admitted','worker admitted after activation releases lock');}
finally{OperationLock::release($lease);foreach($pipes as $pipe)fclose($pipe);proc_terminate($process);proc_close($process);unlink($dir.'/storage/app/operations.lock');rmdir($dir.'/storage/app');rmdir($dir.'/storage');rmdir($dir);}
echo "Service lifecycle: $count PASS\n";
