<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class):void {if(str_starts_with($class,'CajeerEngine\\')) { $file=dirname(__DIR__).'/core/'.str_replace('\\','/',substr($class,13)).'.php';if(is_file($file))require $file; }});
use CajeerEngine\Runtime\{ConfigRepository,PlatformConfiguration};
use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Forum\Security\IdentityMail;
$n=0;$check=function(bool $value,string $label)use(&$n){if(!$value)throw new RuntimeException($label);++$n;};
$reject=function(callable $fn,string $type)use($check){try{$fn();}catch(Throwable $e){$check($e instanceof $type,'correct rejection: '.get_class($e));return;}throw new RuntimeException('Unsafe mutation accepted');};
$root=sys_get_temp_dir().'/ce-platform-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/config');
$remove=function(string $p)use(&$remove):void{if(is_dir($p)&&!is_link($p)){foreach(scandir($p) as $c)if($c!=='.'&&$c!=='..')$remove($p.'/'.$c);rmdir($p);}else unlink($p);};
try{
    file_put_contents($root.'/config/integrations.php','<?php return ["enabled"=>false,"max_connections"=>10,"max_payload_bytes"=>4096];');
    file_put_contents($root.'/config/queue.php','<?php return ["workers"=>["default_limit"=>8]];');
    $cfg=new ConfigRepository($root);$store=new PlatformConfiguration($root);$base=$cfg->platformBaseline();$s=$store->overview($base);
    $check($s['revision']===0&&!$cfg->bool('integrations.enabled'),'server defaults');
    $req=fn(array $s,array $changes)=>['revision'=>$s['revision'],'baseline_hash'=>$s['baseline_hash']]+$changes;
    $next=$store->change($base,$req($s,['overrides'=>['integrations.enabled'=>true,'integrations.max_connections'=>20,'integrations.max_payload_bytes'=>2048,'queue.workers.default_limit'=>3]]),'admin-1');
    $check($next['revision']===1&&$next['effective']['integrations.enabled']===false&&$next['effective']['integrations.max_connections']===10,'environment ceiling');
    $fresh=new ConfigRepository($root);$check($fresh->int('integrations.max_payload_bytes')===2048&&$fresh->int('queue.workers.default_limit')===3,'runtime reload');
    $check($next['history'][0]['actor_id']==='admin-1'&&$next['history'][0]['before']===[],'atomic audit');
    $check((fileperms($root.'/storage/app/platform-config/state.json')&0777)===0600,'private state');
    $reject(fn()=>$store->change($base,$req($s,['overrides'=>[]]),'admin-2'),RevisionConflict::class);
    $reject(fn()=>$store->change($base,$req($next,['overrides'=>['OPENAI_API_KEY'=>'secret']]),'admin-2'),InvalidArgumentException::class);
    $reject(fn()=>$store->change($base,$req($next,['overrides'=>['integrations.max_payload_bytes'=>100]]),'admin-2'),InvalidArgumentException::class);
    $reject(fn()=>$store->change($base,$req($next,['overrides'=>['integrations.enabled'=>'false']]),'admin-2'),InvalidArgumentException::class);
    $reject(fn()=>$store->change($base,$req($next,['overrides'=>[],'rollback_revision'=>1]),'admin-2'),InvalidArgumentException::class);
    $reject(fn()=>$store->change(array_replace($base,['integrations.max_connections'=>2]),$req($next,['overrides'=>[]]),'admin-2'),RevisionConflict::class);
    $check($store->overview($base)['revision']===1,'failed changes leave state intact');
    $rolled=$store->change($base,$req($next,['rollback_revision'=>1]),'admin-2');
    $check($rolled['revision']===2&&$rolled['effective']===$base&&$rolled['history'][0]['action']==='rollback','rollback creates revision');
    $check($store->change($base,$req($rolled,['overrides'=>[]]),'admin-2')['revision']===2,'no-op does not create history');
    $reject(fn()=>$store->change($base,$req($rolled,['rollback_revision'=>999]),'admin-2'),InvalidArgumentException::class);
    $reject(fn()=>$store->change($base,$req($rolled,['overrides'=>[]]),''),InvalidArgumentException::class);
    if(function_exists('pcntl_fork')){
        $pids=[];for($i=0;$i<4;$i++){ $pid=pcntl_fork();if($pid===0){try{$store->change($base,$req($rolled,['overrides'=>['queue.workers.default_limit'=>$i+1]]),'concurrent');exit(0);}catch(RevisionConflict){exit(2);}catch(Throwable){exit(3);}}$pids[]=$pid; }
        $wins=0;$conflicts=0;foreach($pids as $pid){pcntl_waitpid($pid,$status);$code=pcntl_wexitstatus($status);$wins+=(int)($code===0);$conflicts+=(int)($code===2);}
        $check($wins===1&&$conflicts===3&&$store->overview($base)['revision']===3,'concurrent compare-and-swap');
    }
    // Actual TCP conversation against a loopback fixture, not a real mail delivery.
    if(function_exists('pcntl_fork')){
        $server=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$server)throw new RuntimeException('SMTP fixture unavailable');
        $port=substr(strrchr(stream_socket_get_name($server,false),':'),1);
        $pid=pcntl_fork();if($pid===0){$peer=stream_socket_accept($server,5);if(!$peer)exit(3);stream_set_timeout($peer,5);fwrite($peer,"220 test ESMTP\r\n");if(trim((string)fgets($peer))!=='EHLO localhost')exit(4);fwrite($peer,"250 test\r\n");$line=trim((string)fgets($peer));fclose($peer);fclose($server);exit($line==='QUIT'?0:5);}
        foreach(['FORUM_SMTP_HOST'=>'127.0.0.1','FORUM_SMTP_PORT'=>$port,'FORUM_SMTP_SECURITY'=>'plain','FORUM_SMTP_FROM'=>'test@example.com','FORUM_SMTP_USERNAME'=>'','FORUM_SMTP_PASSWORD'=>''] as $key=>$value)putenv($key.'='.$value);
        $ok=(new IdentityMail())->probe();pcntl_waitpid($pid,$status);fclose($server);$check($ok&&pcntl_wexitstatus($status)===0,'SMTP probe authenticates protocol without sending mail');
    }
}finally{$remove($root);}
echo "Platform configuration: $n PASS\n";
