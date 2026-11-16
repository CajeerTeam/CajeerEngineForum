<?php
/** Release policy tests use actual historical files and a real signed source archive. */
declare(strict_types=1);
use CajeerEngine\Update\{UpdatePreflight as P, ReleaseSignature as S, OperationFiles as F};
use CajeerEngine\Release\ReleaseBuilder;
$root=dirname(__DIR__);
spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$p=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
function ok(bool $x,string $s):void{if(!$x)throw new RuntimeException($s);echo "PASS $s\n";}
function reject(callable $f,string $s):void{try{$f();}catch(Throwable){ok(true,$s);return;}throw new RuntimeException('Not rejected: '.$s);}
$m=['edition'=>'forum','mode'=>'dist','version'=>'0.8.3']; P::identity($m,'0.8.2','stable','0.8.3');ok(true,'exact stable version accepted');
reject(fn()=>P::identity($m,'0.8.2','stable','0.8.4'),'approved version mismatch');
reject(fn()=>P::identity($m,'0.8.3','stable',null),'same version/downgrade rejected');
reject(fn()=>P::identity(array_replace($m,['version'=>'0.8.4-rc.1']),'0.8.3','stable',null),'stable channel rejects signed prerelease');
P::identity(array_replace($m,['version'=>'0.8.4-rc.1']),'0.8.3','rc',null);ok(true,'explicit rc channel accepts rc');
reject(fn()=>P::identity(array_replace($m,['version'=>'0.8.4-beta.1']),'0.8.3','rc',null),'rc cannot apply beta');
reject(fn()=>P::identity(array_replace($m,['edition'=>'cms']),'0.8.2','stable',null),'wrong edition');
reject(fn()=>P::identity(array_replace($m,['mode'=>'source']),'0.8.2','stable',null),'source cannot deploy');
$hashes=[];foreach(['sqlite','pgsql','mysql'] as $driver)foreach(glob($root.'/migrations/'.$driver.'/*.sql') as $f)$hashes['migrations/'.$driver.'/'.basename($f)]=F::hash($f);
P::migrations($root,$hashes);ok(true,'all historical SQL checksums');
$first=array_key_first($hashes);$bad=$hashes;$bad[$first]=str_repeat('0',64);reject(fn()=>P::migrations($root,$bad),'signed target changed historical SQL');unset($bad[$first]);reject(fn()=>P::migrations($root,$bad),'target removed historical SQL');
$work=sys_get_temp_dir().'/ce-update-policy-'.bin2hex(random_bytes(8));mkdir($work,0700);
try{
    $built=(new ReleaseBuilder($root))->build(false,'source',$work.'/build');
    S::generate($work.'/secret.key',$work.'/public.key');$sig=S::sign($built['target'],$work.'/secret.key');
    ok(S::verify($built['target'],$built['target'].'.sig',$work.'/public.key',$built['sha256'])['sha256']===$sig['sha256'],'physical signed source digest verified');
    reject(fn()=>S::verify($built['target'],$built['target'].'.sig',$work.'/public.key',str_repeat('0',64)),'wrong approved digest rejected');
    $zip=new PharData($built['target']);$manifest=json_decode($zip['cajeerengine/release-manifest.json']->getContent(),true,512,JSON_THROW_ON_ERROR);
    $space=P::space($root,$built['target'],$manifest,123456);ok($space['expanded_bytes']>0&&$space['backup_estimate_bytes']===123456&&$space['storage_required_bytes']>$space['expanded_bytes'],'real ZIP expanded size and recovery headroom');
    reject(fn()=>P::space($root,$built['target'],$manifest,(int)disk_free_space($root)+1024),'insufficient disk refused before staging');
}finally{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($work);}
