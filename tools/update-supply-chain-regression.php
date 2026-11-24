<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Console/NativeCli.php';
use CajeerEngine\Update\{ReleaseSignature as S, ReleaseTrust as T, OperationFiles as F};
use CajeerEngine\Release\ReleaseDependencyInventory as I;
$n=0;
function check(bool $ok,string $msg):void {global $n;if(!$ok)throw new RuntimeException($msg);++$n;echo "PASS $msg\n";}
function reject(callable $f,string $msg):void {try{$f();}catch(Throwable){check(true,$msg);return;}throw new RuntimeException('Unexpected admission: '.$msg);}
function cleanup(string $path):void {if(!is_dir($path)||is_link($path)){unlink($path);return;}foreach(new FilesystemIterator($path) as $e)cleanup($e->getPathname());rmdir($path);}
$work=sys_get_temp_dir().'/ce-supply-'.bin2hex(random_bytes(8)); F::dir($work); $root=$work.'/root';F::dir($root);
$oldPolicy=getenv('UPDATE_TRUST_POLICY_FILE');$oldKey=getenv('UPDATE_TRUSTED_PUBLIC_KEY_FILE');
try {
    $a=S::generate($work.'/a.secret',$work.'/a.pub');$b=S::generate($work.'/b.secret',$work.'/b.pub');$c=S::generate($work.'/c.secret',$work.'/c.pub');
    $date=static fn(int $s)=>gmdate('Y-m-d\TH:i:s\Z',time()+$s);
    $entry=static fn(array $k,string $file)=>['key_id'=>$k['key_id'],'public_key'=>trim(file_get_contents($file)),'not_before'=>$date(-3600),'not_after'=>$date(3600),'revoked'=>false];
    $one=$entry($a,$work.'/a.pub');$two=$entry($b,$work.'/b.pub');$policy=['schema'=>1,'keys'=>[$one,$two]];$trust=T::fromPolicy($policy);
    $zip=$work.'/artifact.zip';$archive=new PharData($zip,0,null,Phar::ZIP);$archive->addFromString('cajeerengine/release-manifest.json',json_encode(['edition'=>'forum','version'=>'1.5.10','mode'=>'dist']));$archive->addFromString('cajeerengine/file','test');unset($archive);
    S::sign($zip,$work.'/a.secret');$sha=F::hash($zip);$sig=$zip.'.sig';$first=F::read($sig);
    check($trust->verify($sha,$sig,'1.5.10','dist')['key_id']===$a['key_id'],'first publisher accepted during overlap');
    check(S::verify($zip,$sig,$work.'/a.pub')['authorization']['version']==='1.5.10','real ZIP signed with both authorization and legacy digest');
    reject(fn()=>$trust->verify($sha,$sig,'1.5.11','dist'),'signed version substitution rejected');
    reject(fn()=>$trust->verify($sha,$sig,'1.5.10','source'),'signed artifact mode substitution rejected');
    $tamper=$first;$tamper['authorization']['expires_at']=$date(600);F::json($sig,$tamper);reject(fn()=>$trust->verify($sha,$sig,'1.5.10','dist'),'expiry tampering rejected');
    $tamper=$first;unset($tamper['authorization']);F::json($sig,$tamper);reject(fn()=>$trust->verify($sha,$sig,'1.5.10','dist'),'authorization stripping rejected');
    reject(fn()=>$trust->verify($sha,$sig,'1.5.10-rc.1','dist'),'preview authorization stripping rejected');
    check($trust->verify($sha,$sig,'1.5.9','dist')['key_id']===$a['key_id'],'legacy digest path retained for historical packages');
    $secret=base64_decode(trim(file_get_contents($work.'/a.secret')),true);
    $expired=$first;$expired['authorization']=S::authorize($sha,'1.5.10','dist',$secret,time()-91*86400);F::json($sig,$expired);reject(fn()=>$trust->verify($sha,$sig),'authentically signed expired metadata rejected');
    $future=$first;$future['authorization']=S::authorize($sha,'1.5.10','dist',$secret,time()+3600);F::json($sig,$future);reject(fn()=>$trust->verify($sha,$sig),'future-dated authorization rejected');sodium_memzero($secret);
    F::json($sig,$first);$revoked=$one;$revoked['revoked']=true;
    reject(fn()=>T::fromPolicy(['schema'=>1,'keys'=>[$revoked,$two]])->verify($sha,$sig),'revoked signer rejected even with valid signature');
    $past=$one;$past['not_before']=$date(-7200);$past['not_after']=$date(-1);reject(fn()=>T::fromPolicy(['schema'=>1,'keys'=>[$past,$two]])->verify($sha,$sig),'expired signing key rejected');
    $early=$one;$early['not_before']=$date(100);reject(fn()=>T::fromPolicy(['schema'=>1,'keys'=>[$early,$two]])->verify($sha,$sig),'not-yet-active signing key rejected');
    reject(fn()=>T::fromPolicy(['schema'=>1,'keys'=>[$one,$one]]),'duplicate key IDs rejected');
    reject(fn()=>T::fromPolicy(['schema'=>1,'keys'=>[array_replace($one,['key_id'=>str_repeat('f',64)])]]),'key ID cannot impersonate another public key');
    reject(fn()=>T::fromPolicy(['schema'=>1,'keys'=>[array_replace($one,['not_after'=>'2026-02-30T00:00:00Z'])]]),'invalid UTC calendar timestamp rejected');
    S::sign($zip,$work.'/b.secret');check(T::fromPolicy(['schema'=>1,'keys'=>[$revoked,$two]])->verify($sha,$sig,'1.5.10','dist')['key_id']===$b['key_id'],'new publisher accepted after old publisher revoked');
    S::sign($zip,$work.'/c.secret');reject(fn()=>$trust->verify($sha,$sig),'untrusted publisher rejected');
    putenv('UPDATE_TRUST_POLICY_FILE=');putenv('UPDATE_TRUSTED_PUBLIC_KEY_FILE='.$work.'/a.pub');F::json($sig,$first);check(T::configured($root)->verify($sha,$sig,'1.5.10','dist')['key_id']===$a['key_id'],'legacy external trust file works');
    putenv('UPDATE_TRUST_POLICY_FILE='.$work.'/missing.json');reject(fn()=>T::configured($root),'invalid ring does not fall back to legacy key');putenv('UPDATE_TRUST_POLICY_FILE=');
    chmod($work,0777);reject(fn()=>T::configured($root),'writable key ancestor rejected');chmod($work,0700);
    $verified=['ok'=>true,'mode'=>'dist','version'=>'1.5.10','sha256'=>$sha,'key_id'=>$a['key_id']];T::pin($root,$verified);T::pin($root,array_replace($verified,['key_id'=>$b['key_id']]));check(true,'same digest may be re-signed by rotated publisher');
    reject(fn()=>T::pin($root,array_replace($verified,['sha256'=>str_repeat('0',64)])),'different digest for same version blocked across processes');
    reject(fn()=>T::pin($root,array_replace($verified,['ok'=>false])),'unverified release cannot create pin');
    $pin=$root.'/storage/app/updates/trust-pins/'.hash('sha256','1.5.10').'.json';check(F::read($pin)['sha256']===$sha,'persistent pin remains unchanged after conflicting release');
    if(function_exists('pcntl_fork')){
        $child=pcntl_fork();if($child===0){try{T::pin($root,array_replace($verified,['sha256'=>str_repeat('1',64)]));exit(2);}catch(Throwable){exit(0);}}pcntl_waitpid($child,$status);check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'separate process cannot replace a pinned version');
    }
    $lock=file_get_contents(dirname(__DIR__).'/composer.lock');$bom=I::sbom($lock,'1.5.10');$packages=json_decode($lock,true,512,JSON_THROW_ON_ERROR)['packages'];check(count($bom['components'])===count($packages),'CycloneDX inventory covers all locked runtime packages');check($bom===I::sbom($lock,'1.5.10'),'SBOM generation deterministic');
    reject(fn()=>I::sbom('{"packages":[]}','1.5.10'),'empty lock inventory rejected');
    echo "Supply chain: $n PASS\n";
}finally{putenv($oldPolicy===false?'UPDATE_TRUST_POLICY_FILE':'UPDATE_TRUST_POLICY_FILE='.$oldPolicy);putenv($oldKey===false?'UPDATE_TRUSTED_PUBLIC_KEY_FILE':'UPDATE_TRUSTED_PUBLIC_KEY_FILE='.$oldKey);cleanup($work);}
