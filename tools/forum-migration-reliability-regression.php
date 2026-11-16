<?php
/** Real migration, restart, relation batching, theme/extension and public legacy link regression. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,MultiForumSource,LegacyForumUrl};
use CajeerEngine\Forum\Repository\{BoardRepository,ThreadRepository,PostRepository,MemberRepository};
use CajeerEngine\Forum\Service\{DiscussionService,ForumTransaction,ForumThemeService};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\{ForumException,ForumTheme,ForumPageRenderer};
use CajeerEngine\Runtime\{ConfigRepository,ServiceContainer,EventDispatcher,RuntimeLogger};
use CajeerEngine\Extension\{ManifestLoader,CompatibilityChecker};
use CajeerEngine\Extension\Runtime\{PlatformServices,ExtensionContext};
$root=dirname(__DIR__);if(is_file($root.'/vendor/autoload.php'))require $root.'/vendor/autoload.php';
spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$f=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($f))require $f;}});
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function denied(callable $fn,string $message):void{try{$fn();}catch(ForumException $e){check($e->status===404,$message);return;}throw new RuntimeException($message.' unexpectedly allowed');}
$tmp=sys_get_temp_dir().'/ce-migration-reliability-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
$size=1200;foreach($argv as $arg)if(str_starts_with($arg,'--topics='))$size=(int)substr($arg,9);if($size<100||$size>100000)throw new InvalidArgumentException('topics must be 100..100000');
try{
 $source=new PDO('sqlite:'.$tmp.'/source.sqlite');$source->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$source->exec(file_get_contents($root.'/tools/forum-tests/multi_forum_fixture.sql'));
 // Real indexed source rows: two posts per topic; most topics have no watches.
 $source->beginTransaction();$t=$source->prepare("INSERT INTO xf_thread VALUES(?,15,1,?,1600000000,1600000001,'discussion',1,0,'visible')");$p=$source->prepare("INSERT INTO xf_post VALUES(?,?,1,?,1600000000,0,'visible')");
 for($i=0;$i<$size;$i++){ $id=1000+$i;$t->execute([$id,'Bulk needle '.$id]);$p->execute([10000+2*$i,$id,'Bulk searchable '.$id]);$p->execute([10001+2*$i,$id,'Second reply '.$id]); }
 // First large subscription set crosses several batch boundaries, followed by sparse/empty topics.
 $u=$source->prepare("INSERT INTO xf_user VALUES(?,? ,?,1600000000,2,'','secret')");$w=$source->prepare('INSERT INTO xf_thread_watch VALUES(?,?)');
 for($i=10;$i<30;$i++){$u->execute([$i,'Member '.$i,'member'.$i.'@example.test']);$w->execute([20,$i]);}
 $w->execute([1000,1]);$w->execute([1000+$size-1,2]);$source->commit();unset($source);
 mkdir($tmp.'/1');file_put_contents($tmp.'/1/1001-'.str_repeat('b',32).'.data','hello world!');
 putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$tmp.'/target.sqlite');putenv('APP_ENV=testing');putenv('CMS_STORAGE=file');putenv('APP_URL=https://forum.test');
 $config=new ConfigRepository($root);$db=new DatabaseManager($config);$pdo=$db->connection();(new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');
 $admin='e1111111-1111-4111-8111-111111111111';$pdo->exec("INSERT INTO ce_users(id,email,name,password_hash) VALUES('$admin','operator@example.test','Operator','test-hash')");$pdo->exec("INSERT INTO ce_roles(id,handle,name,permissions) VALUES('e2222222-2222-4222-8222-222222222222','admin','Administrator','[\"*\"]')");$pdo->exec("INSERT INTO ce_user_roles(user_id,role_id) SELECT '$admin',id FROM ce_roles WHERE handle='admin'");
 $platform=new PlatformServices();$tx=new ForumTransaction($db,new AuditLogRepository($db),$platform);$actor=new ForumAccessPolicy($admin,['*']);
 $themes=new ForumThemeService($db,$tx);$design=ForumTheme::presets()['ocean'];$state=$themes->snapshot();$state=$themes->change($actor,['revision'=>$state['revision'],'action'=>'draft','design'=>$design]);$themes->change($actor,['revision'=>$state['revision'],'action'=>'activate']);
 $loader=new ManifestLoader();$tpl=$loader->load($root.'/examples/extensions/forum-topic-templates');check((new CompatibilityChecker('1.1','0.7.7'))->isCompatible($tpl),'0.7.7 supports existing topic template package');
 $container=new ServiceContainer();$events=new EventDispatcher();$logger=new RuntimeLogger($tmp);require $tpl->path.'/src/ForumTopicTemplatesProvider.php';(new \CajeerExample\ForumTopicTemplatesProvider())->boot(new ExtensionContext($tmp,$tpl,[],$container,$config,$events,$logger,$platform));
 $announce=$loader->load($root.'/examples/extensions/forum-announcements');check((new CompatibilityChecker('1.1','0.7.7'))->isCompatible($announce),'0.7.7 supports existing announcement package');
 $open=fn()=>new PDO('sqlite:'.$tmp.'/source.sqlite');$source=$open();$adapter=new MultiForumSource($source,'xf_','xenforo','2.3.0','large');$engine=new ForumMigrationEngine($db,$adapter,$tmp);
 $job=$engine->start('test-backup')['job']['id'];$ds=new DiscussionService($db,$tx,new BoardRepository($db),new ThreadRepository($db),new PostRepository($db),new MemberRepository($db));
 try{ForumMigrationEngine::assertNoRunning($db);throw new LogicException('incomplete import allowed');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'incomplete forum import'),'maintenance exit and second job fence');}
 denied(fn()=>$ds->legacyLocation($job,'topics','20'),'incomplete job redirect denied');
 $started=hrtime(true);$steps=0;$watchSteps=0;$injected=false;$reopened=false;
 for(; $steps<10000; $steps++){
  $before=$engine->progress()['job'];$phase=(int)$before['phase'];
  if($phase===9){$watchSteps++;if(!$injected){
   // A partial watcher batch must not advance either checkpoint on rollback.
   $pdo->exec("CREATE TRIGGER inject_import_failure BEFORE INSERT ON ce_forum_watches BEGIN SELECT RAISE(ABORT,'simulated disk/write failure'); END");
   try{$engine->step(8);throw new LogicException('expected injected failure');}catch(PDOException){}
   $after=$engine->progress()['job'];check($before['cursor_id']===$after['cursor_id']&&$before['subcursor_id']===$after['subcursor_id'],'failed relation batch rolls back both cursors');
   $pdo->exec('DROP TRIGGER inject_import_failure');$injected=true;
   $engine->step(8);$partial=$engine->progress()['job'];check((int)$partial['subcursor_id']>0,'partial watcher checkpoint persisted');
   $source->rollBack();unset($engine,$adapter,$source);$source=$open();$adapter=new MultiForumSource($source,'xf_','xenforo','2.3.0','large');$engine=new ForumMigrationEngine($db,$adapter,$tmp);$reopened=true;
  }}
  $r=$engine->step(100);if($r['job']['state']==='complete')break;
 }
 $report=$engine->status();check($report['job']['state']==='complete'&&$reopened&&$injected,'large import completes after interruption');
 check($watchSteps<ceil($size/50)+10,'sparse subscription phase uses bounded multi-object batches');
 check($report['relation_rows']['watches']===24,'all 24 watches preserved without duplicates');
 check((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_posts')->fetchColumn()===2*$size+2,'all imported posts preserved');
 check((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_extension_events')->fetchColumn()===0,'historical import does not emit extension events');
 check($themes->active()===$design&&count($platform->forumPanels('composer'))===2,'active theme and real plugin registrations survive import');
 check((new \CajeerEngine\Forum\Service\ForumSearchMaintenance($db))->check()['ok'],'full-text index verified');
 ForumMigrationEngine::assertNoRunning($db);check(true,'completed job allows maintenance exit');
 $id=fn($kind,$old)=>ForumMigrationEngine::mapping($db,$job,$kind,$old)['mapping']['target_id']??null;
 check(LegacyForumUrl::resolve('phpbb','viewtopic.php',['p'=>'30'])===['posts','30'],'phpBB legacy parser');
 check(LegacyForumUrl::resolve('mybb','thread-20.html',[])===['topics','20'],'MyBB legacy parser');
 check(LegacyForumUrl::resolve('smf','index.php',['topic'=>'20.msg30'])===['posts','30'],'SMF legacy parser');
 denied(fn()=>LegacyForumUrl::resolve('phpbb','viewtopic.php',['t'=>['20']]),'invalid legacy query rejected');
 denied(fn()=>LegacyForumUrl::resolve('xenforo','https://evil.test/threads/20',[]),'external legacy path rejected');
 $thread=$id('topics',20);$post=$id('posts',30);$board=$id('forums',15);$cat=$report['job']['category_id'];
 denied(fn()=>$ds->legacyLocation($job,'topics','20'),'quarantined topic cannot redirect');
 $pdo->exec("UPDATE ce_forum_categories SET visibility='public' WHERE id='$cat'");$pdo->exec("UPDATE ce_forum_boards SET visibility='public',state='open',seo_index=1 WHERE category_id='$cat'");
 check($ds->legacyUrl($job,'threads/example.20/',[])==='/forum/threads/'.$thread,'XenForo legacy path resolves');
 check($ds->legacyLocation($job,'topics','20')==='/forum/threads/'.$thread,'public legacy topic resolves');
 check($ds->legacyLocation($job,'posts','30')==='/forum/threads/'.$thread.'?page=1#post-'.$post,'legacy post resolves to actual page and anchor');
 check($ds->legacyLocation($job,'forums','15')==='/forum/boards/'.$board,'public legacy board resolves');
 // Actual SSR on the same migrated data, with the active design and registered plugin.
 if(class_exists(\Symfony\Component\HttpFoundation\Request::class)){
  $renderer=new ForumPageRenderer($ds,$root,null,$platform);$res=$renderer->render(\Symfony\Component\HttpFoundation\Request::create('/forum/boards/'.$board),new ForumAccessPolicy('',[]));
  check($res->getStatusCode()===200&&str_contains($res->getContent(),'forum-extension-topic-templates')&&str_contains($res->getContent(),'id="forum-theme"'),'migrated board SSR includes real theme and plugin templates');
 }
 $pdo->exec("UPDATE ce_forum_boards SET visibility='hidden' WHERE id='$board'");denied(fn()=>$ds->legacyLocation($job,'posts','30'),'revoked ACL denies legacy post');
 $pdo->exec("UPDATE ce_forum_boards SET visibility='public' WHERE id='$board'");$pdo->exec("UPDATE ce_forum_posts SET deleted_at=1 WHERE id='$post'");denied(fn()=>$ds->legacyLocation($job,'posts','30'),'deleted post cannot redirect');
 denied(fn()=>$ds->legacyLocation($job,'topics','21'),'skipped source topic cannot redirect');
 $engine->step(100);check((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_posts')->fetchColumn()===2*$size+2,'completed rerun creates no duplicates');
 echo json_encode(['topics'=>$size,'posts'=>2*$size+2,'batches'=>$steps+1,'watch_batches'=>$watchSteps,'elapsed_seconds'=>round((hrtime(true)-$started)/1e9,3),'peak_memory_bytes'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR)."\n";
}finally{unset($engine,$adapter,$source,$db,$pdo);foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($tmp);}
