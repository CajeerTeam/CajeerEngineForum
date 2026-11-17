#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $name)use($root):void {
    if(!str_starts_with($name,'CajeerEngine\\'))return;
    $file=$root.'/core/'.str_replace('\\','/',substr($name,13)).'.php';
    if(is_file($file))require_once $file;
});
function ok(bool $yes,string $message):void {if(!$yes)throw new RuntimeException($message);echo "PASS $message\n";}
function denied(callable $action,string $message):void {try{$action();}catch(InvalidArgumentException|RuntimeException|\CajeerEngine\Forum\ForumException){ok(true,$message);return;}throw new RuntimeException('Accepted unsafe operation: '.$message);}
function rmTree(string $path):void {if(!is_dir($path))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($path);}
$work=sys_get_temp_dir().'/forum-092-security-'.bin2hex(random_bytes(8));mkdir($work,0700);
try{
    $repo=new \CajeerEngine\Integration\WebhookRepository($work);
    foreach(['http://8.8.8.8/callback','https://127.0.0.1/a','https://10.10.1.2/a','https://169.254.169.254/metadata','file:///etc/passwd','https://8.8.8.8:8443/','https://user:secret@8.8.8.8/a','https://[::1]/','https://[::ffff:127.0.0.1]/'] as $url){
        denied(fn()=>$repo->create(['url'=>$url]),'stored webhook SSRF rejected: '.$url);
    }
    ok(count($repo->all())===0,'SSRF attempts never enter persistent webhook registry');
    denied(fn()=>$repo->create(['url'=>'https://8.8.8.8/events','secret'=>'weak']), 'caller-controlled webhook HMAC secret denied');
    $item=$repo->create(['url'=>'https://8.8.8.8/events','events'=>['forum.created']]);
    ok(preg_match('/^whsec_[a-f0-9]{64}$/D',(string)$item['secret'])===1,'cryptographically random 256-bit webhook HMAC secret issued');
    ok(count($repo->all())===1,'public HTTPS webhook registered');
    denied(fn()=>$repo->update($item['id'],['url'=>'https://127.0.0.1/']), 'webhook SSRF update rejected');
    ok($repo->find($item['id'])['url']==='https://8.8.8.8/events','failed webhook update is atomic');
    $repo->delete($item['id']);ok(count($repo->all())===0,'webhook removed');
    denied(fn()=>\CajeerEngine\Forum\Service\AttachmentService::validate('evil.php.png',"payload"),'double extension blocked');
    denied(fn()=>\CajeerEngine\Forum\Service\AttachmentService::validate('../evil.jpg',"payload"),'path traversal upload blocked');
    denied(fn()=>\CajeerEngine\Forum\Service\AttachmentService::validate('evil.svg','<svg/>'),'active SVG upload blocked');
    denied(fn()=>\CajeerEngine\Forum\Service\AttachmentService::validate('doc.pdf','<html><script>1</script></html>'),'MIME-spoofed document denied');
    $hash=\CajeerEngine\Security\AuthService::csrfToken('ce_'.str_repeat('a',64));
    ok(strlen($hash)===64 && $hash!==\CajeerEngine\Security\AuthService::csrfToken('ce_'.str_repeat('b',64)),'CSRF secrets independent per session');
    $pkg=$work.'/extension';mkdir($pkg);file_put_contents($pkg.'/payload.php','<?php echo 1;');
    ok(isset(\CajeerEngine\Extension\PackageTrust::inventory($pkg)['payload.php']),'extension package inventory hashes files');
    symlink($work.'/extension/payload.php',$pkg.'/evil.php');
    denied(fn()=>\CajeerEngine\Extension\PackageTrust::inventory($pkg),'symlink in extension package denied');
    unlink($pkg.'/evil.php');
    file_put_contents($pkg.'/cajeer.extension.json',json_encode(['name'=>'example/security','type'=>'plugin','version'=>'1.0.0','engine'=>'^1.1'],JSON_THROW_ON_ERROR));
    $pair=sodium_crypto_sign_keypair();
    $priv=$work.'/sign.key';file_put_contents($priv,base64_encode(sodium_crypto_sign_secretkey($pair)));chmod($priv,0600);
    $trust=$work.'/trusted';mkdir($trust,0700);
    $pub=$trust.'/example.pub';file_put_contents($pub,base64_encode(sodium_crypto_sign_publickey($pair)));chmod($pub,0644);
    $manifest=(new \CajeerEngine\Extension\ManifestLoader())->load($pkg);
    \CajeerEngine\Extension\PackageTrust::sign($pkg,$priv);
    ok(\CajeerEngine\Extension\PackageTrust::verify($pkg,$manifest,$trust)['status']==='verified','real Ed25519 extension package verifies with operator public key');
    chmod($pub,0666);clearstatcache(true,$pub);
    denied(fn()=>\CajeerEngine\Extension\PackageTrust::verify($pkg,$manifest,$trust),'writable extension publisher key rejected');
    chmod($pub,0644);unlink($pub);symlink($priv,$pub);
    denied(fn()=>\CajeerEngine\Extension\PackageTrust::verify($pkg,$manifest,$trust),'symlinked publisher trust key rejected');
    unlink($pub);file_put_contents($pub,base64_encode(sodium_crypto_sign_publickey($pair)));chmod($pub,0644);
    file_put_contents($pkg.'/payload.php','<?php echo "tampered";');
    denied(fn()=>\CajeerEngine\Extension\PackageTrust::verify($pkg,$manifest,$trust),'tampered signed extension code rejected');
    echo "0.9.2 hardening regressions passed\n";
}finally{rmTree($work);}
