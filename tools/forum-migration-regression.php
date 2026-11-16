<?php
/** Runs actual PHP import against a disposable phpBB-shaped SQLite snapshot and real forum schema. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,PhpbbSource,PhpbbText};
use CajeerEngine\Runtime\ConfigRepository;
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$f=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($f))require $f;}});
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
check(PhpbbText::plain('<r><B><s>[b]</s>Текст<e>[/b]</e></B> &amp; &lt;script&gt;</r>')==='Текст & <script>','s9e literal conversion');
check(PhpbbText::plain('[b:abc]Reply[/b:abc]','abc')==='[b]Reply[/b]','legacy UID removal');
if(in_array('--text-only',$argv,true))exit(0);
$tmp=sys_get_temp_dir().'/ce-import-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
try{
 $source=new PDO('sqlite:'.$tmp.'/source.sqlite');$source->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$source->exec(file_get_contents($root.'/tools/forum-tests/phpbb_fixture.sql'));
 file_put_contents($tmp.'/2_safe','hello world!');
 putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$tmp.'/target.sqlite');putenv('APP_ENV=testing');
 $db=new DatabaseManager(new ConfigRepository($root));$pdo=$db->connection();(new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');
 $pdo->exec("INSERT INTO ce_users(id,email,name,password_hash) VALUES('existing','existing@example.test','Existing','existing-hash')");
 $adapter=new PhpbbSource($source,'phpbb_','test');$engine=new ForumMigrationEngine($db,$adapter,$tmp);
 check($engine->plan()['counts']['posts']['count']===4&&!$engine->exists(),'read-only preflight');
 try{$source->exec("DELETE FROM phpbb_posts");throw new RuntimeException('source write unexpectedly succeeded');}catch(PDOException){echo "PASS source write denied\n";}
 $job=$engine->start('test-backup')['job']['id'];
 // A failed SQL write rolls back both the mapped group and the checkpoint.
 $pdo->exec("CREATE TRIGGER reject_import BEFORE INSERT ON ce_forum_import_map BEGIN SELECT RAISE(ABORT,'injected'); END");
 try{$engine->step(1);throw new RuntimeException('expected injected failure');}catch(PDOException){echo "PASS batch rollback\n";}
 check((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_import_map')->fetchColumn()===0&&(int)$engine->status()['job']['cursor_id']===0,'checkpoint rollback');
 $pdo->exec('DROP TRIGGER reject_import');$engine->step(1);
 // Recreate source and engine as a new process would do.
 $source->rollBack();unset($engine,$adapter,$source);$source=new PDO('sqlite:'.$tmp.'/source.sqlite');$engine=new ForumMigrationEngine($db,new PhpbbSource($source,'phpbb_','test'),$tmp);
 for($i=0;$i<100;$i++){if($engine->step(1)['job']['state']==='complete')break;}
 $report=$engine->status();check($report['job']['state']==='complete','resumed all phases');
 $id=fn($kind,$sourceId)=>ForumMigrationEngine::mapping($db,$job,$kind,$sourceId)['mapping']['target_id']??null;
 $topic=$id('topics',20);$post=$id('posts',30);$member=$id('users',2);
 check($topic!==null&&$post!==null&&$id('topics',21)===null&&$id('topics',22)===null&&$id('users',4)===null,'visibility, empty topics and collision handling');
 check($pdo->query("SELECT COUNT(*) FROM ce_forum_posts WHERE thread_id='$topic'")->fetchColumn()==2&&$pdo->query("SELECT read_sequence FROM ce_forum_threads WHERE id='$topic'")->fetchColumn()==2,'publication order');
 check($pdo->query("SELECT status FROM ce_users WHERE id='$member'")->fetchColumn()==='disabled'&&$pdo->query("SELECT password_hash FROM ce_users WHERE id='$member'")->fetchColumn()!=='legacy-secret','credentials not imported');
 check($pdo->query("SELECT COUNT(*) FROM ce_forum_member_preferences WHERE user_id='$member' AND profile_visibility='private'")->fetchColumn()==1,'profile privacy');
 check($pdo->query("SELECT COUNT(*) FROM ce_forum_boards WHERE id='".$id('forums',11)."' AND visibility='hidden' AND state='readonly' AND seo_index=0")->fetchColumn()==1,'quarantined forum');
 check($report['relation_rows']===['watches'=>2,'board_watches'=>1,'votes'=>2],'poll votes and watches');
 check($pdo->query("SELECT file_data FROM ce_forum_attachments WHERE id='".$id('attachments',40)."'")->fetchColumn()===base64_encode('hello world!')&&$id('attachments',41)===null&&$id('attachments',42)===null,'attachment bytes and rejection');
 check((new \CajeerEngine\Forum\ForumIntegrityCheck($db))->check()['ok'],'target integrity');
 check((new \CajeerEngine\Forum\Service\ForumSearchMaintenance($db))->check()['ok'],'FTS integrity');
 check($pdo->query('SELECT COUNT(*) FROM ce_forum_extension_events')->fetchColumn()==0&&$pdo->query('SELECT COUNT(*) FROM ce_forum_mail_delivery')->fetchColumn()==0,'no historical mail or events');
 $engine->step(1);check($pdo->query('SELECT COUNT(*) FROM ce_forum_posts')->fetchColumn()==2,'completed import idempotent');
}finally{unset($engine,$source,$db,$pdo);foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($tmp);}
