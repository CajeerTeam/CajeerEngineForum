<?php
/** Policy and real local-catalog regression, independent of external service availability. */
declare(strict_types=1);
require dirname(__DIR__).'/core/Console/NativeCli.php';
use CajeerEngine\Update\{ReleasePath as P, UpdateCompatibility as C, UpdatePreflight as F, UpdateResolver};
function test(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function rejects(callable $fn,string $message):void {try{$fn();}catch(Throwable){test(true,$message);return;}throw new RuntimeException('Not rejected: '.$message);}
function rel(string $v):array{return ['version'=>$v,'channel'=>F::channelOf($v),'artifacts'=>['dist'=>['name'=>'cajeerengine-'.$v.'-dist.zip']]];}
$releases=array_map('rel',['1.6.0','1.5.4','1.5.2','1.5.3-rc.1','1.5.3-beta.1','1.5.3','2.0.0-dev.1','1.4.9']);
$plan=P::plan($releases,'1.5.1','stable',true);
test(array_column($plan['steps'],'version')===['1.5.2','1.5.3','1.5.4','1.6.0'],'stable sequential sorted path');
test($plan['latest']['version']==='1.6.0'&&$plan['next']['version']==='1.5.2','latest differs from next');
test(array_column($plan['steps'],'kind')===['patch','patch','patch','minor'],'patch and minor classification');
test(P::plan($releases,'1.5.2','stable',true)['next']['version']==='1.5.3','replan after actual activation');
test(P::plan($releases,'1.5.1','stable',false)['next']['version']==='1.6.0','explicit nonsequential discovery');
test(P::plan($releases,'1.5.2','rc',true)['next']['version']==='1.5.3-rc.1','rc excludes beta');
test(P::plan($releases,'1.5.2','beta',true)['next']['version']==='1.5.3-beta.1','beta excludes rc');
test(P::plan($releases,'1.5.3-rc.1','rc',true)['next']['version']==='1.5.3','stable promotion');
test(P::plan($releases,'1.6.0','dev',true)['steps'][0]['kind']==='major','dev major plan');
test(P::plan($releases,'2.0.0','stable',true)['next']===null,'no downgrade');
test(P::compare('1.5.3-rc.10','1.5.3-rc.2')>0&&P::compare('1.5.3-z','1.5.3')<0&&P::compare('1.5.3-1','1.5.3-alpha')<0,'SemVer precedence');
rejects(fn()=>P::plan($releases,'1.5.1','bad',true),'invalid channel refused');
$dup=$releases;$dup[]=rel('1.5.2');test(P::plan($dup,'1.5.1','stable',true)['next']['version']==='1.5.3','duplicate version excluded');
$policy=json_decode(file_get_contents(dirname(__DIR__).'/release.json'),true,512,JSON_THROW_ON_ERROR)['upgrade_compatibility'];
$m=['edition'=>'forum','mode'=>'dist','version'=>'1.5.2','upgrade_compatibility'=>$policy];
F::identity($m,'1.5.1','stable','1.5.2');test(true,'signed source floor accepts predecessor');
rejects(fn()=>F::identity($m,'1.5.0','stable',null),'mandatory intermediate release enforced');
rejects(fn()=>C::validate(array_replace($policy,['min_from'=>'1.5.2']),'1.5.2'),'impossible source floor rejected');
rejects(fn()=>C::validate(array_replace($policy,['extensions'=>['../bad']]),'1.5.2'),'malformed requirements rejected');
$r=['php'=>'8.4.1','extensions'=>$policy['extensions'],'database_driver'=>'pgsql','database_version'=>'17.0','redis_enabled'=>true,'redis_version'=>'7.4.0'];
test(C::evaluate($policy,$r)['ok'],'compatible platform accepted');
foreach([['php'=>'8.3.0'],['extensions'=>[]],['database_version'=>'16.9'],['database_driver'=>'unknown'],['redis_version'=>'6.2'],['redis_version'=>null]] as $i=>$bad)test(!C::evaluate($policy,array_replace($r,$bad))['ok'],'runtime rejection '.$i);
test(C::evaluate($policy,array_replace($r,['redis_enabled'=>false,'redis_version'=>null]))['ok'],'optional disabled Redis');
test(C::databaseIdentity('mysql','5.5.5-10.11.6-MariaDB')===['mariadb','10.11.6'],'MariaDB compatibility prefix');
test(C::databaseIdentity('pgsql','17.2 (Debian)')===['pgsql','17.2'],'PostgreSQL server identity');
$tmp=tempnam(sys_get_temp_dir(),'ce-plan-');try{file_put_contents($tmp,json_encode(['releases'=>$releases],JSON_THROW_ON_ERROR));$resolver=new UpdateResolver(['core'=>['releases_url'=>$tmp,'channel'=>'stable']]);$res=$resolver->check('1.5.1');test($res['next']['version']==='1.5.2'&&count($res['upgrade_path'])===4,'real resolver returns ordered path');}finally{unlink($tmp);}
