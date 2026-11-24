<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Console/NativeCli.php';
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\{UpdateCenterPolicy as C,UpdatePolicy as P,OperationFiles as F,OperationLock as L,UpdateManager as M,UpdateOperator as O};
function check(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('FAIL '.$name);echo "PASS $name\n";}
function reject(callable $fn,string $name):void {try{$fn();}catch(Throwable $e){check(true,$name);return;}throw new RuntimeException('FAIL '.$name);}
function clean(string $dir):void {foreach(new FilesystemIterator($dir) as $f){if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$root=sys_get_temp_dir().'/ce-update-center-'.bin2hex(random_bytes(8));F::dir($root.'/config');F::dir($root.'/storage/app');
try {
 $config=['policy'=>['acp_enabled'=>false,'mode'=>'manual_approval','auto_apply_enabled'=>false,'blocked_versions'=>'1.5.11'],'core'=>['channel'=>'stable','sequential'=>true],'web_upgrade'=>['enabled'=>true],'operator'=>['enabled'=>true]];
 $write=static function()use(&$config,$root){F::write($root.'/config/updates.php','<?php return '.var_export($config,true).';');};$write();
 F::write($root.'/VERSION','2.1.0');F::json($root.'/release.json',['edition'=>'forum','edition_version'=>'1.5.9','upstream_version'=>'2.1.0','version'=>'2.1.0']);
 $c=new ConfigRepository($root);$store=new C($root);$v=$store->overview($c);check(!$v['editable'],'host-only default');
 $body=['revision'=>$v['revision'],'baseline_hash'=>$v['baseline_hash'],'settings'=>$v['settings'],'confirm'=>true];reject(fn()=>$store->save($c,$body,'admin'),'host delegation required');
 $config['policy']['acp_enabled']=true;$write();$c=new ConfigRepository($root);$v=$store->overview($c);$body['baseline_hash']=$v['baseline_hash'];
 $body['settings']['mode']='automatic_patch';$body['settings']['timezone']='Europe/Warsaw';$body['settings']['window_start']='23:00';$body['settings']['window_end']='02:00';$body['settings']['blocked_versions']='1.5.10';
 foreach(['channel'=>'dev','sequential'=>false,'auto_apply'=>true,'timezone'=>'Invalid/TZ','window_end'=>'23:00','mode'=>'nonsense','blocked_versions'=>'../bad','arbitrary_command'=>'bad'] as $k=>$value){$bad=$body;$bad['settings'][$k]=$value;reject(fn()=>$store->save($c,$bad,'admin'),'reject '.$k);}
 $bad=$body;$bad['confirm']=false;reject(fn()=>$store->save($c,$bad,'admin'),'confirmation required');
 $r=$store->save($c,$body,'admin');check($r['revision']===1&&$r['history'][0]['actor']==='admin','durable revision and audit');
 $fresh=new ConfigRepository($root);$p=new P($fresh);check($p->snapshot()['mode']==='automatic_patch'&&$p->snapshot()['timezone']==='Europe/Warsaw','new process configuration consumes persisted policy');
 check($p->stageReason('1.5.9','1.5.10')==='version_blocked'&&$p->stageReason('1.5.9','1.5.11')==='version_blocked','host and ACP blocklists both enforced');
 check(!$p->autonomousEnabled(),'host autonomous cap retained');reject(fn()=>$store->save($fresh,$body,'admin'),'stale revision denied');
 $body['revision']=1;$h=$store->lock(false);try{reject(fn()=>$store->save($fresh,$body,'admin'),'active operator lease blocks policy writes');}finally{L::release($h);}
 $config['policy']['auto_apply_enabled']=true;$write();$fresh=new ConfigRepository($root);reject(fn()=>$store->save($fresh,$body,'admin'),'stale host baseline denied');
 $body['baseline_hash']=$store->overview($fresh)['baseline_hash'];$body['settings']['auto_apply']=true;$r=$store->save($fresh,$body,'admin');check((new P(new ConfigRepository($root)))->autonomousEnabled(),'explicit host authorization enables autonomous apply');
 $body['revision']=2;$body['settings']=null;$r=$store->save($fresh,$body,'admin');check($r['revision']===3&&(new P(new ConfigRepository($root)))->snapshot()['mode']==='manual_approval','reset restores host policy without erasing audit');
 $m=new M($root,$fresh);$id='20261124-053408-'.str_repeat('a',16);F::json($root.'/storage/app/updates/last-update.json',['id'=>$id,'state'=>'committed','target_version'=>'1.5.9','previous_version'=>'1.5.8']);
 check($m->wizardState()['rollback']['transaction']===$id,'committed rollback candidate displayed');reject(fn()=>$m->request('rollback',['confirm'=>true,'transaction'=>'20261124-053408-'.str_repeat('b',16)],'admin'),'stale rollback candidate refused');
 $req=$m->request('rollback',['confirm'=>true,'transaction'=>$id],'admin');check($req['transaction']===$id,'exact rollback transaction queued');
 F::write($root.'/storage/app/update-requests/center-policy.json','{broken');$broken=new ConfigRepository($root);check(!(new P($broken))->stagingEnabled()&&!(new P($broken))->autonomousEnabled()&&!$store->overview($broken)['editable'],'corrupt state fails closed');
 $config['policy']['acp_enabled']=false;$write();$off=new ConfigRepository($root);check((new P($off))->snapshot()['mode']==='manual_approval','host revocation ignores ACP state');
 $api=new CajeerEngine\Api\ApiContract(dirname(__DIR__));$schema=$api->operation('api.v1.updates.policy.save')['requestBody']['content']['application/json']['schema'];check($api->validate(json_decode(json_encode($body)),$schema)===[],'reset request conforms to API validation');
 $route=array_values(array_filter(CajeerEngine\Http\RouteRegistry::all(),static fn($r)=>$r[2]==='api.v1.updates.policy.save'))[0];check($route[5]===['*'],'route requires administrator wildcard');
}finally{clean($root);}
