<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Console/NativeCli.php';
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\{AutonomousUpdate as A, UpdatePolicy as P, UpdateOperator as O, UpdateManager as M, OperationFiles as F, OperationLock as L, GitHubEndpoints as G};
function test(bool $ok,string $message):void { if(!$ok)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message.PHP_EOL; }
function removeTree(string $dir):void {foreach(new FilesystemIterator($dir) as $e){if($e->isDir()&&!$e->isLink())removeTree($e->getPathname());else unlink($e->getPathname());}rmdir($dir);}
function version(string $root,string $v):void {F::json($root.'/release.json',['edition'=>'forum','version'=>'2.1.0','upstream_version'=>'2.1.0','edition_version'=>$v]);}
$root=sys_get_temp_dir().'/ce-auto-'.bin2hex(random_bytes(8)); F::dir($root.'/storage/app');
try {
    $cfg=new ConfigRepository($root); $cfg->set('updates.core.channel','stable');
    $cfg->set('updates.operator.enabled',true); $cfg->set('updates.web_upgrade.enabled',true);
    F::write($root.'/VERSION',"2.1.0\n");version($root,'1.5.5');F::write($root.'/storage/app/installed.lock','installed');
    $cfg->set('updates.policy.mode','automatic_patch');test(!(new P($cfg))->autonomousEnabled(),'existing policy does not silently opt into installation');
    foreach (['legacy','disabled','notify_only','download_only','manual_approval','automatic_security','automatic_patch','automatic_minor','fully_automatic'] as $mode) {
        $cfg->set('updates.policy',['mode'=>$mode,'auto_apply_enabled'=>true]);
        test((new P($cfg))->autonomousEnabled()===in_array($mode,['automatic_security','automatic_patch','automatic_minor','fully_automatic'],true),'autonomous gate '.$mode);
    }
    $cfg->set('updates.policy',['mode'=>'automatic_security','auto_apply_enabled'=>true]);
    test((new P($cfg))->autonomousReason('1.5.5','1.5.6',false,time())==='security_release_required','unsigned security label never authorizes apply');
    $cfg->set('updates.policy',['mode'=>'automatic_patch','auto_apply_enabled'=>true]);
    test((new P($cfg))->autonomousReason('1.5.5','1.6.0',true,time())==='patch_release_required','minor denied by patch policy');
    if (PHP_OS_FAMILY!=='Linux'||!function_exists('posix_geteuid')||posix_geteuid()===0) {echo "SKIP non-root operator integration (run under dedicated UID)\n";return;}
    $stage=['id'=>str_repeat('a',32),'sha256'=>str_repeat('b',64),'from_version'=>'1.5.5','version'=>'1.5.6'];
    $dir=$root.'/storage/app/update-requests'; F::dir($dir); F::json($dir.'/staged.json',$stage,0644);
    $private=$root.'/storage/app/updates/autonomous';
    $checks=0;$verifies=0;$executes=0;$metadata='fresh';$next='1.5.6';$digest=null;$signed=true;$outcome='busy';
    $engine=new A($root,$cfg,function($from)use(&$checks,&$metadata,&$next,&$digest){++$checks;return ['metadata_status'=>$metadata,'metadata_source'=>G::RELEASES_URL,'next'=>['version'=>$next,'artifacts'=>['dist'=>['sha256'=>$digest]]]];},function($path,$sha)use(&$verifies,&$signed,$stage){++$verifies;return ['ok'=>$signed,'version'=>$stage['version'],'sha256'=>$sha,'mode'=>'dist','security_update'=>false];},function($options)use(&$executes,&$outcome,$root,$stage){
        ++$executes; test($options['autonomous']===true&&$options['sha256']===$stage['sha256']&&$options['from_version']==='1.5.5','exact stage reaches application boundary');
        if ($outcome==='busy') return (new M($root,new ConfigRepository($root)))->apply($options);
        if ($outcome==='failed')return ['ok'=>false];
        if ($outcome==='unknown') {F::json($root.'/storage/app/updates/active.json',['state'=>'applying']);throw new RuntimeException('interrupted');}
        version($root,'1.5.6');$id='20261124-010101-'.str_repeat('c',16);
        F::json($root.'/storage/app/updates/last-update.json',['ok'=>true,'state'=>'committed','previous_version'=>'1.5.5','target_version'=>'1.5.6','sha256'=>$stage['sha256'],'id'=>$id]);return ['ok'=>true,'id'=>$id];
    });
    test($engine->run()['state']==='waiting_for_stage'&&$executes===0,'public staged receipt alone cannot authorize auto apply');
    $engine->rememberStage($stage);test((fileperms($private)&0777)===0700&&(fileperms($private.'/stage.json')&0777)===0600,'private UID-bound authorization receipt');
    $cfg->set('updates.policy.defer_until',gmdate('Y-m-d\TH:i:s\Z',time()+3600));
    test($engine->run()['reason']==='outside_maintenance_window'&&$checks===0,'closed window performs no network or apply');
    $cfg->set('updates.policy.defer_until','');
    $cfg->set('updates.policy.blocked_versions','1.5.6');test($engine->run()['reason']==='version_blocked'&&$checks===0,'blocked version denied before network');$cfg->set('updates.policy.blocked_versions','');
    $request=$dir.'/request-'.str_repeat('d',32).'.json';F::json($request,['state'=>'pending']);
    test($engine->run()['reason']==='manual_request_pending'&&$checks===0,'manual approval takes precedence');F::remove($request);
    F::json($dir.'/staged.json',array_replace($stage,['id'=>str_repeat('e',32)]));test($engine->run()['reason']==='stage_changed'&&$checks===0,'changed public stage cannot replace private authorization');F::json($dir.'/staged.json',$stage);
    $metadata='offline';test($engine->run()['reason']==='fresh_catalog_required'&&$executes===0,'offline catalog blocks installation');test($engine->run()['reason']==='retry_cooldown'&&$checks===1,'retry cooldown persists');F::remove($private.'/retry.json');
    $metadata='not_modified';$next='1.5.7';test($engine->run()['reason']==='candidate_changed'&&$executes===0,'new catalog must still select exact next step');F::remove($private.'/retry.json');$next='1.5.6';
    $digest=str_repeat('f',64);test($engine->run()['reason']==='catalog_digest_changed'&&$executes===0,'changed advertised digest requires restaging');F::remove($private.'/retry.json');$digest=null;
    $signed=false;test($engine->run()['reason']==='package_verification_failed'&&$executes===0,'invalid package is rejected before apply');F::remove($private.'/retry.json');$signed=true;
    $cfg->set('updates.policy.mode','automatic_security');test($engine->run()['reason']==='security_release_required'&&$executes===0,'verified security classification rechecked');F::remove($private.'/retry.json');$cfg->set('updates.policy.mode','automatic_patch');
    $lease=L::acquire($root,false);try {test($engine->run()['reason']==='operation_busy'&&$executes===1&&glob($private.'/attempt-*.json')===[],'actual busy operation lease yields safe retry without mutation');}finally{L::release($lease);}F::remove($private.'/retry.json');
    $outcome='failed';test($engine->run()['state']==='failed'&&$executes===2,'definite failure is durably recorded');
    test($engine->run()['reason']==='transition_already_attempted'&&$executes===2,'failed transition never loops');
    $attempt=glob($private.'/attempt-*.json')[0];F::remove($attempt);F::remove($private.'/retry.json');
    $outcome='unknown';test($engine->run()['state']==='recovery_required'&&$executes===3,'interrupted apply requires recovery');
    $operator=new O($root,$cfg);test($operator->runOnce()['status']==='recovery_required','actual operator blocks while active marker exists');F::remove($root.'/storage/app/updates/active.json');
    test(!$engine->reconcile()&&$operator->runOnce()['status']==='recovery_required','missing commit evidence blocks operator after marker loss');
    $tx='20261124-020202-'.str_repeat('d',16);
    F::json($root.'/storage/app/updates/last-update.json',['state'=>'rolled_back','id'=>$tx]);
    F::json($root.'/storage/app/updates/transactions/'.$tx.'/journal.json',['id'=>$tx,'state'=>'rolled_back','previous_version'=>'1.5.5','target_version'=>'1.5.6','sha256'=>str_repeat('f',64),'at'=>gmdate(DATE_ATOM)]);
    test(!$engine->reconcile(),'unrelated rollback cannot reconcile autonomous attempt');
    F::json($root.'/storage/app/updates/transactions/'.$tx.'/journal.json',['id'=>$tx,'state'=>'rolled_back','previous_version'=>'1.5.5','target_version'=>'1.5.6','sha256'=>$stage['sha256'],'at'=>gmdate(DATE_ATOM)]);
    test($engine->reconcile()&&F::read($attempt)['state']==='failed','exact rollback reconciles as failed, never replayable');
    F::remove($attempt);F::remove($private.'/retry.json');$outcome='completed';
    test($engine->run()['state']==='completed'&&$executes===4&&F::read($attempt)['state']==='completed','durable exact commit completes attempt');
    $r=F::read($attempt);$r['state']='running';F::json($attempt,$r);test($engine->reconcile()&&F::read($attempt)['state']==='completed','restart reconciles exact committed release without replay');
    test($engine->run()['state']==='waiting_for_stage'&&$executes===4,'new installed version invalidates old stage');
    test(glob($dir.'/request-*.json')===[],'no forged administrator approval created');
} finally {removeTree($root);}
