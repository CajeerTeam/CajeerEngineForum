<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Console/NativeCli.php';
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\{UpdatePolicy as P, UpdateOperator as O, UpdateManager as M, OperationFiles as F, AutomaticReleaseDiscovery as D, AutomaticReleaseStaging as S};
function pass(bool $ok,string $message):void { if(!$ok)throw new RuntimeException('FAIL '.$message); echo 'PASS '.$message.PHP_EOL; }
function rmTree(string $dir):void {foreach(new FilesystemIterator($dir) as $e){if($e->isDir()&&!$e->isLink())rmTree($e->getPathname());else unlink($e->getPathname());}rmdir($dir);}
function ts(string $date):int{return (new DateTimeImmutable($date))->getTimestamp();}
$root=sys_get_temp_dir().'/ce-scheduling-'.bin2hex(random_bytes(8)); F::dir($root.'/storage/app');
try {
    $cfg=new ConfigRepository($root); $cfg->set('updates.core.channel','dev');
    $cfg->set('updates.operator.enabled',true);$cfg->set('updates.web_upgrade.enabled',true);
    F::write($root.'/storage/app/installed.lock','installed');
    $cfg->set('updates.core.auto_stage_enabled',false);
    pass((new D($cfg))->enabled()&&!(new S($root,$cfg))->enabled(),'legacy defaults unchanged');
    $cfg->set('updates.core.auto_stage_enabled',true);pass((new S($root,$cfg))->enabled(),'legacy staging opt-in retained');
    foreach(['disabled','notify_only','download_only','manual_approval','automatic_security','automatic_patch','automatic_minor','fully_automatic'] as $mode){
        $cfg->set('updates.policy.mode',$mode);$p=new P($cfg);
        pass($p->discoveryEnabled()===($mode!=='disabled')&&$p->stagingEnabled()===(!in_array($mode,['disabled','notify_only'],true))&&!$p->snapshot()['autonomous_apply_enabled'],'actual gates: '.$mode);
    }
    $cfg->set('updates.policy.mode','automatic_patch');$p=new P($cfg);
    pass($p->stageReason('1.5.3','1.5.4')===null,'patch eligible');
    pass($p->stageReason('1.5.3','1.6.0')==='patch_release_required','minor denied by patch policy');
    pass($p->stageReason('1.5.3','1.5.4-rc.1')==='patch_release_required','preview denied by patch policy even on dev channel');
    $cfg->set('updates.policy.mode','automatic_minor');$p=new P($cfg);
    pass($p->stageReason('1.5.3','1.6.0')===null&&$p->stageReason('1.5.3','2.0.0')==='minor_or_patch_required','minor policy denies major');
    $cfg->set('updates.policy.mode','automatic_security');$p=new P($cfg);
    pass($p->stageReason('1.5.3','1.5.4',null)===null&&$p->stageReason('1.5.3','1.5.4',false)==='security_release_required'&&$p->stageReason('1.5.3','1.5.4',true)===null,'security policy requires verified classification at publication');
    $cfg->set('updates.policy.mode','fully_automatic');$cfg->set('updates.policy.blocked_versions','1.5.4');$p=new P($cfg);
    pass($p->stageReason('1.5.3','1.5.4')==='version_blocked'&&$p->requestReason('apply','1.5.4',time(),false)==='version_blocked','blocked candidate denied before download and manual approval');
    pass($p->requestReason('rollback',null,time())===null&&$p->requestReason('backup',null,time())===null,'backup and rollback independent of update policy');
    $cfg->set('updates.policy',['mode'=>'manual_approval','timezone'=>'Europe/Warsaw','days'=>'1','window_start'=>'23:00','window_end'=>'02:00']);$p=new P($cfg);
    pass(!$p->windowOpen(ts('2026-11-23 22:59:59 Europe/Warsaw'))&&$p->windowOpen(ts('2026-11-23 23:00:00 Europe/Warsaw')),'inclusive start on Monday');
    pass($p->windowOpen(ts('2026-11-24 01:59:59 Europe/Warsaw'))&&!$p->windowOpen(ts('2026-11-24 02:00:00 Europe/Warsaw')),'overnight window belongs to start day; exclusive end');
    pass(!$p->windowOpen(ts('2026-11-24 23:00:00 Europe/Warsaw'))&&$p->nextWindow(ts('2026-11-24 02:00:01 Europe/Warsaw'))===ts('2026-11-30 23:00:00 Europe/Warsaw'),'next weekly opening is correct');
    pass($p->requestReason('apply','1.5.4',ts('2026-11-24 03:00 Europe/Warsaw'))==='outside_maintenance_window'&&$p->requestReason('apply','1.5.4',ts('2026-11-24 03:00 Europe/Warsaw'),false)===null&&$p->requestReason('download','1.5.4',ts('2026-11-24 03:00 Europe/Warsaw'))===null,'approval can queue outside window, download unaffected');
    $cfg->set('updates.policy',['mode'=>'manual_approval','timezone'=>'Europe/Warsaw','days'=>'7','window_start'=>'02:15','window_end'=>'02:45']);$p=new P($cfg);
    pass($p->nextWindow(ts('2026-03-29T00:00:00Z'))===ts('2026-04-05 02:15:00 Europe/Warsaw'),'spring DST nonexistent window skipped');
    pass($p->windowOpen(ts('2026-10-25T00:30:00Z'))&&$p->windowOpen(ts('2026-10-25T01:30:00Z')),'both real fall DST occurrences accepted');
    $cfg->set('updates.policy',['mode'=>'manual_approval','defer_until'=>'2026-12-01T03:00:00Z']);$p=new P($cfg);
    pass(!$p->windowOpen(ts('2026-12-01T02:59:59Z'))&&$p->nextWindow(ts('2026-11-01T00:00:00Z'))===ts('2026-12-01T03:00:00Z'),'not-before deferral is exact');
    foreach([['mode'=>'wrong'],['timezone'=>'Not/Real'],['days'=>'0'],['window_start'=>'03:00'],['window_start'=>'03:00','window_end'=>'03:00'],['defer_until'=>'2026-02-30T00:00:00Z'],['blocked_versions'=>'../bad']] as $bad){
        $cfg->set('updates.policy',$bad);$p=new P($cfg);pass(!$p->snapshot()['valid']&&!$p->discoveryEnabled()&&!$p->stagingEnabled()&&$p->requestReason('apply','1.5.4',time())==='invalid_policy','fail closed invalid '.array_key_first($bad));
    }
    $cfg->set('updates.policy',['mode'=>'notify_only']);$calls=0;$auto=new S($root,$cfg,function()use(&$calls){++$calls;return ['state'=>'staged'];});
    pass(!$auto->due()&&$auto->run()['state']==='skipped'&&$calls===0,'notify-only prevents actual staging invocation');
    $cfg->set('updates.policy',['mode'=>'download_only']);pass($auto->run()['state']==='staged'&&$calls===1,'download-only invokes actual staging service');
    pass(!is_dir($root.'/storage/app/update-requests'),'no apply approval created by automatic staging');
    // Exercise the persisted operator and real request consumer without touching production data.
    $id=str_repeat('a',32);$dir=$root.'/storage/app/update-requests';F::dir($dir);
    $file=$dir.'/request-'.$id.'.json';$record=['id'=>$id,'state'=>'pending','action'=>'apply','version'=>'1.5.4','actor'=>'test-admin','requested_at'=>gmdate(DATE_ATOM),'confirmed_at'=>gmdate(DATE_ATOM),'stage'=>str_repeat('b',32),'sha256'=>str_repeat('c',64)];F::json($file,$record);
    $cfg->set('updates.policy',['mode'=>'manual_approval','defer_until'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600)]);
    $manager=new M($root,$cfg);$r=$manager->runRequest($id);
    pass(($r['deferred']??false)&&F::read($file)===$record,'direct request consumer retains exact pending approval outside window');
    if (PHP_OS_FAMILY==='Linux' && function_exists('posix_geteuid') && posix_geteuid()!==0) {
        $executions=0;$operator=new O($root,$cfg,function(string $id)use(&$executions,$file){++$executions;$r=F::read($file);$r['state']='completed';F::json($file,$r);return ['ok'=>true];});
        pass($operator->runOnce()['status']==='idle'&&$executions===0&&F::read($file)===$record,'operator defers pending request without calling executor');
        $cfg->set('updates.policy',['mode'=>'manual_approval']);pass($operator->runOnce()['status']==='completed'&&$executions===1,'opening window consumes prior approval exactly once');
        pass($operator->runOnce()['status']==='idle'&&$executions===1,'completed approval is never replayed');
        F::json($file,$record);$cfg->set('updates.policy',['mode'=>'manual_approval','blocked_versions'=>'1.5.4']);
        pass($operator->runOnce()['status']==='failed'&&$executions===1&&F::read($file)['state']==='failed','newly blocked pending approval durably fails without executor');
    } else {
        $cfg->set('updates.policy',['mode'=>'manual_approval','blocked_versions'=>'1.5.4']);
        pass(!$manager->runRequest($id)['ok']&&F::read($file)['state']==='failed','real request consumer rejects blocked version durably');
    }
} finally {rmTree($root);}
