<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Console/NativeCli.php';
use CajeerEngine\Update\{AutomaticReleaseStaging as A, PartialRelease as P, OperationFiles as F, GitHubAssetTransport as T};
use CajeerEngine\Runtime\ConfigRepository;
function check(bool $ok,string $name):void { if (!$ok) throw new RuntimeException('FAIL '.$name); echo 'PASS '.$name.PHP_EOL; }
function deny(callable $fn,string $name):void {try{$fn();}catch(RuntimeException){check(true,$name);return;}throw new RuntimeException('FAIL '.$name);}
function erase(string $path):void {foreach(new FilesystemIterator($path) as $e){if($e->isDir()&&!$e->isLink())erase($e->getPathname());else unlink($e->getPathname());}rmdir($path);}
$root=sys_get_temp_dir().'/ce-stage-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    check(T::responseOffset(206,['content-range'=>'bytes 400-999/1000'],400,1000)===400,'exact resumed range accepted');
    check(T::responseOffset(200,[],400,1000)===0,'ignored range restarts at zero');
    foreach(['bytes 399-999/1000','bytes 400-999/1001','bytes 400-998/1000','bytes 400-999/*'] as $r)deny(fn()=>T::responseOffset(206,['content-range'=>$r],400,1000),'reject '.$r);
    deny(fn()=>T::responseOffset(206,['content-range'=>'bytes 0-999/1000'],0,1000),'unsolicited partial denied');
    deny(fn()=>T::responseOffset(200,['content-encoding'=>'gzip'],0,1000),'encoded bytes denied');
    deny(fn()=>T::responseOffset(200,['content-range'=>'bytes 400-999/1000'],400,1000),'ambiguous full response denied');
    $dir=$root.'/downloads/1.5.3';F::dir($dir);$part=$dir.'/.partial.zip';$receipt=$dir.'/.partial.json';
    $identity=['version'=>'1.5.3','size'=>1000,'sha256'=>str_repeat('a',64),'signature_sha256'=>str_repeat('b',64),'tag'=>'v1.5.3'];
    $stream=P::open($part,$receipt,$identity);fwrite($stream,str_repeat('x',400));fflush($stream);fsync($stream);fclose($stream);
    $stream=P::open($part,$receipt,$identity);check(ftell($stream)===400,'durable partial reopened at byte 400');fwrite($stream,str_repeat('y',600));fclose($stream);
    check(file_get_contents($part)===str_repeat('x',400).str_repeat('y',600),'resume preserves exact prefix and appends');
    $other=array_replace($identity,['signature_sha256'=>str_repeat('c',64)]);$stream=P::open($part,$receipt,$other);check(ftell($stream)===0,'changed signature invalidates partial');fclose($stream);
    F::write($part,str_repeat('z',1001));$stream=P::open($part,$receipt,$other);check(ftell($stream)===0,'oversized partial reset');fclose($stream);
    F::remove($part);symlink('/dev/null',$part);deny(fn()=>P::open($part,$receipt,$other),'symlink partial denied');unlink($part);
    F::write($part,'old');touch($part,time()-700000);F::write($dir.'/cajeerengine-1.5.3-dist.zip','verified');touch($dir.'/cajeerengine-1.5.3-dist.zip',time()-700000);
    check(P::cleanup($root.'/downloads')===1&&!is_file($part)&&is_file($dir.'/cajeerengine-1.5.3-dist.zip'),'cleanup preserves published packages');
    F::dir($root.'/storage/app');F::write($root.'/storage/app/installed.lock','installed');$cfg=new ConfigRepository($root);
    $cfg->set('updates',['core'=>['auto_stage_enabled'=>false],'operator'=>['enabled'=>true],'web_upgrade'=>['enabled'=>true]]);
    $calls=0;$auto=new A($root,$cfg,function()use(&$calls){$calls++;return ['state'=>'staged','version'=>'1.5.3'];});
    check(!$auto->due()&&$auto->run()['state']==='skipped'&&$calls===0,'opt-in disabled by default');
    $cfg->set('updates.core.auto_stage_enabled',true);
    $cfg->set('updates.core.auto_check_enabled',false);
    check(!$auto->due()&&$auto->run()['state']==='skipped'&&$calls===0,'host discovery kill switch blocks automatic staging');
    $cfg->set('updates.core.auto_check_enabled',true);
    check($auto->due(),'enabled idle operator is due');
    $r=$auto->run();check($r['ok']&&$r['state']==='staged'&&$calls===1,'successful automatic staging recorded');
    check(!$auto->due()&&$auto->run()['state']==='skipped'&&$calls===1,'persisted successful cooldown');
    $status=$root.'/storage/app/updates/auto-stage.json';F::json($status,['next_attempt_at'=>0]);
    $failed=new A($root,$cfg,fn()=>throw new RuntimeException('private credential'));
    $r=$failed->run();check(!$r['ok']&&$r['failures']===1&&$r['next_attempt_at']>=time()+59,'failure persists retry cooldown');
    check(!str_contains(file_get_contents($status),'private credential'),'failure state redacts exception details');
    F::json($status,['next_attempt_at'=>0,'failures'=>1]);$r=$failed->run();check($r['failures']===2&&$r['next_attempt_at']>=time()+119,'exponential retry survives restart');
    F::json($status,['next_attempt_at'=>0]);F::json($root.'/storage/app/updates/active.json',['state'=>'running']);check(!$auto->due(),'recovery blocks automatic staging');
    F::remove($root.'/storage/app/updates/active.json');mkdir($root.'/storage/app/updates/active.json');
    check(!$auto->due(),'malformed active marker fails closed');rmdir($root.'/storage/app/updates/active.json');
    check(!is_dir($root.'/storage/app/update-requests'),'automatic staging never generates approval');
} finally {erase($root);}
