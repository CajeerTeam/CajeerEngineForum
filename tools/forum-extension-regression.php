<?php
/** Real SDK/renderer tests; --database runs the real PHP DB path in a disposable SQLite DB. */
declare(strict_types=1);
use CajeerEngine\Extension\{ManifestLoader,CompatibilityChecker,ExtensionManifest};
use CajeerEngine\Extension\Runtime\{PlatformServices,ExtensionContext};
use CajeerEngine\Runtime\{ConfigRepository,ServiceContainer,EventDispatcher,RuntimeLogger};
use CajeerEngine\Forum\Extension\ForumExtension;
use CajeerEngine\Forum\Service\{ForumExtensionOutbox,ForumTransaction};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\AuditLogRepository;
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$f=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($f))require $f;}});
function check(bool $ok,string $name): void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function rejects(callable $fn,string $name): void {try{$fn();}catch(Throwable){check(true,$name);return;}throw new RuntimeException('Expected rejection: '.$name);}
function cleanup(string $dir): void {if(!is_dir($dir))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($dir);}
$tmp=sys_get_temp_dir().'/ce-forum-extension-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
try{
 $config=new ConfigRepository($root);$container=new ServiceContainer();$events=new EventDispatcher();$logger=new RuntimeLogger($tmp);$platform=new PlatformServices();$loader=new ManifestLoader();
 $manifest=$loader->load($root.'/examples/extensions/forum-announcements');
 check((new CompatibilityChecker('1.1','0.7.4'))->isCompatible($manifest)&&!(new CompatibilityChecker('1.1','0.7.3'))->isCompatible($manifest)&&(new CompatibilityChecker('1.1','0.8.0'))->isCompatible($manifest)&&(new CompatibilityChecker('1.1','1.0.0'))->isCompatible($manifest)&&!(new CompatibilityChecker('1.1','1.1.0'))->isCompatible($manifest),'Forum and engine version constraints');
 check($loader->validatePayload(['name'=>'test/a','type'=>'plugin','version'=>'1.0.0','engine'=>'^1.1','permissions'=>['forum.events']])!==[],'missing Forum version rejected');
 $context=new ExtensionContext($tmp,$manifest,['enabled_at'=>gmdate(DATE_ATOM,time()-10)],$container,$config,$events,$logger,$platform);
 $sdk=new ForumExtension($context);$seen=[];$sdk->listen('first','forum.post.reply',function($payload,$event)use(&$seen){$seen[]=[$payload['event_id'],$event];});
 check($platform->forumSubscribers('forum.post.reply')===['cajeer--forum-announcements.first']&&$platform->forumSubscribers('forum.post.reply',1)===[],'subscription selection and activation cutoff');
 check($platform->deliverForumEvent('cajeer--forum-announcements.first','forum.post.reply',['event_id'=>'abc','occurred_at'=>time()])&&$seen===[['abc','forum.post.reply']],'real subscriber callback');
 check(!$platform->deliverForumEvent('cajeer--forum-announcements.first','forum.thread.create',['event_id'=>'abc','occurred_at'=>time()]),'wrong event cannot invoke subscriber');
 rejects(fn()=> $sdk->listen('first','forum.post.reply',fn()=>null),'duplicate registration rejected');
 rejects(fn()=> $sdk->listen('private','forum.conversation.message',fn()=>null),'private events not supported');
 $sdk->panel('malicious','home',fn()=>['title'=>'<script>','text'=>'<img src=x onerror=alert(1)>']);$sdk->panel('broken','home',fn()=>throw new RuntimeException('plugin failed'));
 $sdk->panel('invalid','home',fn()=>['title'=>'bad','text'=>str_repeat('x',4001)]);
 $r=new ReflectionClass(\CajeerEngine\Forum\ForumPageRenderer::class);$renderer=$r->newInstanceWithoutConstructor();$r->getProperty('platform')->setValue($renderer,$platform);$html=$r->getMethod('extensionPanels')->invoke($renderer,'home');
 check(str_contains($html,'&lt;script&gt;')&&str_contains($html,'&lt;img')&&!str_contains($html,'<img')&&count($platform->forumPanels('home'))===1,'actual SSR slot escapes HTML and isolates invalid panels');
 $no=new ExtensionContext($tmp,new ExtensionManifest('test/no','plugin','1.0.0','*'),[],$container,$config,$events,$logger,$platform);
 rejects(fn()=>(new ForumExtension($no))->panel('no','home',fn()=>null),'forum.ui permission required');
 rejects(fn()=>(new ForumExtension($no))->listen('no','forum.post.reply',fn()=>null),'forum.events permission required');
 $tpl=$loader->load($root.'/examples/extensions/forum-topic-templates');require $tpl->path.'/src/ForumTopicTemplatesProvider.php';
 (new \CajeerExample\ForumTopicTemplatesProvider())->boot(new ExtensionContext($tmp,$tpl,[],$container,$config,$events,$logger,$platform));
 check(count($platform->forumPanels('composer'))===2&&str_contains($platform->forumPanels('composer')[0]['text'],'Шаги воспроизведения'),'actual topic template provider');
 if(!in_array('--database',$argv,true)){cleanup($tmp);exit(0);}
 if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite required for --database');
 putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$tmp.'/forum.sqlite');putenv('APP_ENV=testing');$db=new DatabaseManager(new ConfigRepository($root));$pdo=$db->connection();(new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');
 $outbox=new ForumExtensionOutbox($db);
 $container->instance(DatabaseManager::class,$db);$p=new PlatformServices();$tx=new ForumTransaction($db,new AuditLogRepository($db),$p);$ctx=new ExtensionContext($tmp,$manifest,[],$container,$config,$events,$logger,$p);
 $migration=require $manifest->path.'/migrations/0001_announcements.php';$migration($ctx,$pdo);require $manifest->path.'/src/ForumAnnouncementsProvider.php';(new \CajeerExample\ForumAnnouncementsProvider())->boot($ctx);
 $p->execute('cajeer--forum-announcements','save',['revision'=>'0','title'=>'Announcement','body'=>'Real content','starts_at'=>'0','ends_at'=>'0'],true);
 check($p->forumPanels('home')[0]['text']==='Real content','real SQL announcement editor and rendering');
 rejects(fn()=> $p->execute('cajeer--forum-announcements','save',['revision'=>'0','title'=>'Stale','body'=>'x'],true),'announcement optimistic revision');
 $p->execute('cajeer--forum-announcements','save',['revision'=>'1','title'=>'Scheduled','body'=>'later','starts_at'=>(string)(time()+100)],true);check($p->forumPanels('home')===[],'future announcement not visible');
 try{$tx->write(function()use($tx){$tx->audit('forum.thread.create',null,['thread_id'=>'rollback']);throw new RuntimeException('rollback');});}catch(RuntimeException $e){if($e->getMessage()!=='rollback')throw $e;}
 check($outbox->diagnostics()['pending']===0,'transaction rollback removes event');
 // Failure of another subscriber must not repeat the successful announcement aggregate.
 $sdk2=new ForumExtension($ctx);$sdk2->listen('fails','forum.thread.create',fn()=>throw new RuntimeException('secret error'));
 $tx->write(fn()=> $tx->audit('forum.thread.create',null,['thread_id'=>'topic','body'=>'private body','email'=>'private email']));
 $row=$pdo->query('SELECT * FROM ce_forum_extension_events')->fetch();$data=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);check(!array_key_exists('body',$data)&&!array_key_exists('email',$data)&&$data['thread_id']==='topic','metadata allowlist');
 $sdk2->listen('late','forum.thread.create',fn()=>throw new RuntimeException('late listener must not run'));
 check(!in_array('cajeer--forum-announcements.late',json_decode($row['targets'],true),true),'subscriber snapshot at mutation');
 $outbox->drain($p,10);check($p->execute('cajeer--forum-announcements','overview',[],false)['data']['activity']['topics']==1,'worker executes real plugin once');
 for($i=0;$i<7;$i++){$pdo->exec("UPDATE ce_forum_extension_events SET due_at=0 WHERE state='pending'");$outbox->drain($p,10);}
 check($outbox->diagnostics()['dead']===1&&$p->execute('cajeer--forum-announcements','overview',[],false)['data']['activity']['topics']==1,'bounded retry, successful subscriber not repeated');
 $dead=$outbox->deadLetters()[0];check(!str_contains((string)$dead['last_error'],'secret error')&&$outbox->retry($dead['id']),'safe diagnostics and operator retry');
 // Fresh platform without the failed listener simulates disable after restarting workers.
 $p2=new PlatformServices();$c2=new ExtensionContext($tmp,$manifest,[],$container,$config,$events,$logger,$p2);(new \CajeerExample\ForumAnnouncementsProvider())->boot($c2);$outbox->drain($p2,10);
 check($outbox->diagnostics()['done']===1,'disabled subscriber skipped on retry');
 $p2->deliverForumEvent('cajeer--forum-announcements.topics','forum.thread.create',$data+['event_id'=>$row['id'],'occurred_at'=>(int)$row['created_at']]);check($p2->execute('cajeer--forum-announcements','overview',[],false)['data']['activity']['topics']==1,'idempotent plugin receipt');
}finally{cleanup($tmp);}
