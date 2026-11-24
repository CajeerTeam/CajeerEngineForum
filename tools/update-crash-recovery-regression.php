<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Console/NativeCli.php';
use CajeerEngine\Update\{OperationFiles as F,OperationLock as L,UpdateManager as M,RecoveryJournal as J};
use CajeerEngine\Runtime\ConfigRepository;
$n=0; function check(bool $ok,string $msg):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$msg);$n++;echo "PASS $msg\n";}
function fail(callable $fn,string $msg):void {try{$fn();}catch(Throwable){check(true,$msg);return;}check(false,$msg);}
function rmTree(string $dir):void {foreach(new FilesystemIterator($dir) as $e){if($e->isDir()&&!$e->isLink())rmTree($e->getPathname());else unlink($e->getPathname());}rmdir($dir);}
$root=sys_get_temp_dir().'/ce-crash-'.bin2hex(random_bytes(8)); F::dir($root.'/storage/app');
try {
 $id='20261124-010101-'.str_repeat('a',16);$updates=$root.'/storage/app/updates';$dir=$updates.'/transactions/'.$id;F::dir($dir);$active=$updates.'/active.json';$tx=$dir.'/journal.json';
 $j=['id'=>$id,'recovery_protocol'=>2,'state'=>'migrating','previous_version'=>'1.5.8','target_version'=>'1.5.9','sha256'=>str_repeat('b',64),'mutation_started'=>true,'maintenance_was_enabled'=>false,'maintenance_before'=>null,'backup'=>'not-a-real-backup','child_lock'=>'child-'.str_repeat('c',16).'.lock','at'=>gmdate(DATE_ATOM)];
 F::write($root.'/VERSION',"2.1.0\n");$version=static function($v)use($root){F::json($root.'/release.json',['edition'=>'forum','version'=>'2.1.0','upstream_version'=>'2.1.0','edition_version'=>$v]);};$version('1.5.9');
 F::json($active,$j);$terminal=array_replace($j,['state'=>'committed']);F::json($tx,$terminal);F::json($root.'/storage/app/maintenance.json',['enabled'=>true]);
 $m=new M($root,new ConfigRepository($root));check(J::active($root)['state']==='committed','stale active pointer cannot hide durable commit');
 F::write($root.'/live-data.txt','post-commit sentinel');$r=$m->rollback(null,false,$id);check($r['state']==='committed'&&$r['reconciled']&&!L::active($root),'committed cleanup does not restore nonexistent backup');check(file_get_contents($root.'/live-data.txt')==='post-commit sentinel','committed cleanup preserves live data');check(F::read($updates.'/last-update.json')['sha256']===$j['sha256'],'terminal report contains exact digest');
 fail(fn()=>$m->rollback(null,false,$id),'no automatic rollback once active marker is gone');
 $version('1.5.8');$before=['enabled'=>true,'message'=>'manual maintenance'];$rolled=array_replace($j,['state'=>'rolled_back','restored'=>true,'maintenance_was_enabled'=>true,'maintenance_before'=>$before]);F::json($active,$j);F::json($tx,$rolled);$r=$m->rollback(null,false,$id);check($r['state']==='rolled_back'&&!L::active($root),'completed restore is not repeated');check(F::read($root.'/storage/app/maintenance.json')===$before,'manual maintenance preserved');
 F::json($active,$j);F::json($tx,array_replace($terminal,['sha256'=>str_repeat('e',64)]));fail(fn()=>$m->rollback(null,false,$id),'mismatched transaction blocks recovery');check(L::active($root),'invalid evidence leaves gate closed');
 F::json($tx,$terminal);fail(fn()=>$m->rollback(null,false,$id),'wrong installed version blocks terminal cleanup');
 $version('1.5.9');$child=L::childGuard($dir.'/'.$j['child_lock']);try{fail(fn()=>$m->rollback(null,false,$id),'live child lease blocks recovery');}finally{L::release($child);}
 $m->rollback(null,false,$id);check(!L::active($root),'recovery succeeds after child exits');
 $version('1.5.8');$early=array_replace($j,['state'=>'prepared','mutation_started'=>false,'backup'=>null]);F::json($active,$early);F::json($tx,$early);$r=$m->rollback(null,false,$id);check($r['state']==='rolled_back'&&!L::active($root),'pre-mutation crash needs no database restore');
 if (posix_geteuid()!==0) {
  // Run the independent supervisor in a separate PHP process against real journals.
  F::dir($root.'/bin');$native=dirname(__DIR__).'/core/Console/NativeCli.php';
  F::write($root.'/bin/cajeer', '<?php require '.var_export($native,true).'; exit(CajeerEngine\\Console\\OperationsCli::run(dirname(__DIR__),$argv));');
  $version('1.5.9');F::json($active,$j);F::json($tx,$terminal);$cmd=[PHP_BINARY,dirname(__DIR__).'/tools/update-supervisor.php',$root];
  $p=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);check(proc_close($p)===0&&!L::active($root),'independent supervisor finalizes real terminal journal');
  F::json($active,$j);F::json($tx,$terminal);F::json($dir.'/supervisor-retries.json',['id'=>$id,'attempts'=>3,'next_at'=>0]);$p=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);check(proc_close($p)!==0&&L::active($root),'persistent recovery budget prevents loops');
 }
}finally{rmTree($root);}echo "Crash recovery: $n PASS\n";
