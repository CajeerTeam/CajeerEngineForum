<?php
/** Native PDO integration: actual source adapters and the same target importer used by CLI. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,MultiForumSource};
use CajeerEngine\Runtime\ConfigRepository;
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$f=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($f))require $f;}});
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$tmp=sys_get_temp_dir().'/ce-multi-import-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
try{
 $source=new PDO('sqlite:'.$tmp.'/source.sqlite');$source->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$source->exec(file_get_contents($root.'/tools/forum-tests/multi_forum_fixture.sql'));unset($source);
 foreach(['1/1001-'.str_repeat('b',32).'.data','1/1001-'.str_repeat('a',32).'.data','202009/post_1_safe.attach','1/40_'.str_repeat('a',40)] as $file){$path=$tmp.'/'.$file;if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,'hello world!');}
 putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$tmp.'/target.sqlite');putenv('APP_ENV=testing');
 $db=new DatabaseManager(new ConfigRepository($root));$pdo=$db->connection();(new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');
 $pdo->exec("INSERT INTO ce_users(id,email,name,password_hash) VALUES('existing','existing@example.test','Existing','existing-hash')");
 $jobs=[];
 foreach(['xenforo'=>['xf_','2.3.0'],'mybb'=>['mybb_','1.8.39'],'smf'=>['smf_','2.1.6']] as $platform=>[$prefix,$version]){
  $open=fn()=>new PDO('sqlite:'.$tmp.'/source.sqlite');$source=$open();$adapter=new MultiForumSource($source,$prefix,$platform,$version,'same-label');
  $engine=new ForumMigrationEngine($db,$adapter,$tmp,null,4);$plan=$engine->plan();
  check($plan['platform']===$platform&&$plan['counts']['topics']['count']===3&&$plan['counts']['source_private_messages']===1&&!$engine->exists(),"$platform preflight and source counts");
  if($platform==='xenforo'){
   check($plan['pipeline_version']===4&&$plan['counts']['profiles']['count']===3&&$plan['counts']['reactions']['count']===6&&$plan['counts']['follows']['count']===3&&$plan['counts']['avatars']['count']===0,'XF extended plan and immutable source counts');
   check(count($adapter->additionalBatch('reactions',100,105,3))===3,'XF additional keyset and upper bound');
  } else check($plan['pipeline_version']===1,"$platform legacy pipeline unchanged");
  $tree=$adapter->batch('forums',0,3,3);check((int)$tree[0]['forum_type']===0&&(int)$tree[1]['parent_id']===(int)$tree[0]['forum_id'],"$platform topological ordering");
  // SMF synthesizes category IDs above UINT32. Keyset bounds must be bound as
  // integers: SQLite compares computed UNION columns differently from strings.
  if($platform==='smf'){
   $last=$adapter->batch('board_watches',16,(int)$plan['counts']['board_watches']['high'],10);
   check(count($last)===1&&(int)$last[0]['forum_id']>4294967296,'SMF wide category ID survives relation keyset pagination');
  }

  try{$source->exec('DELETE FROM '.$prefix.($platform==='smf'?'members':($platform==='xenforo'?'user':'users')));throw new RuntimeException('Source write succeeded');}catch(PDOException){echo "PASS $platform source read-only\n";}
  $job=$engine->start('test-backup')['job']['id'];$jobs[]=$job;
  $pdo->exec("CREATE TRIGGER reject_import BEFORE INSERT ON ce_forum_import_map BEGIN SELECT RAISE(ABORT,'injected'); END");
  try{$engine->step(1);throw new RuntimeException('Expected failure');}catch(PDOException){}
  check((int)$engine->status()['job']['cursor_id']===0,"$platform rollback checkpoint");$pdo->exec('DROP TRIGGER reject_import');$engine->step(1);
  $source->rollBack();unset($engine,$adapter,$source);$source=$open();$adapter=new MultiForumSource($source,$prefix,$platform,$version,'same-label');$engine=new ForumMigrationEngine($db,$adapter,$tmp,null,4);
  for($i=0;$i<100;$i++){if($engine->step(1)['job']['state']==='complete')break;}
  $report=$engine->status();check($report['job']['state']==='complete'&&$report['job']['platform']===$platform,"$platform completed after resume");
  $id=fn($kind,$old)=>ForumMigrationEngine::mapping($db,$job,$kind,$old)['mapping']['target_id']??null;
  $user=$id('users',1);$thread=$id('topics',20);$post=$id('posts',30);$attachment=$id('attachments',40);$board=$id('forums',15);
  check($user&&$thread&&$post&&$attachment&&$board&&$id('users',3)===null&&$id('topics',21)===null&&$id('topics',22)===null&&$id('posts',32)===null&&$id('attachments',41)===null&&$id('attachments',42)===null,"$platform valid entities, conflict and visibility filtering");
  check($pdo->query("SELECT status FROM ce_users WHERE id='$user'")->fetchColumn()==='disabled'&&$pdo->query("SELECT password_hash FROM ce_users WHERE id='$user'")->fetchColumn()!=='secret',"$platform source user 1 preserved, credentials not copied");
  check($pdo->query("SELECT COUNT(*) FROM ce_forum_posts WHERE thread_id='$thread'")->fetchColumn()==2&&$pdo->query("SELECT read_sequence FROM ce_forum_threads WHERE id='$thread'")->fetchColumn()==2,"$platform publication order");
  check($pdo->query("SELECT COUNT(*) FROM ce_forum_boards WHERE id='$board' AND visibility='hidden' AND state='readonly' AND seo_index=0")->fetchColumn()==1,"$platform quarantine");
  check($pdo->query("SELECT file_data FROM ce_forum_attachments WHERE id='$attachment'")->fetchColumn()===base64_encode('hello world!'),"$platform actual attachment bytes");
  if($platform==='xenforo'){
   check((int)$report['job']['phase']===16,'XF community relations and content conversion completed after original and extended phases');
   $aliceProfile=$id('profiles',1);$bobProfile=$id('profiles',2);
   check($aliceProfile===$user&&$bobProfile===$id('users',2)&&$id('profiles',3)===null,'XF profiles mapped only to newly imported users');
   check($pdo->query("SELECT bio FROM ce_forum_member_profiles WHERE user_id='$user'")->fetchColumn()==='Imported biography','XF profile saved, not just mapped');
   check($pdo->query("SELECT body_format FROM ce_forum_posts WHERE id='$post'")->fetchColumn()==='bbcode','XF final post conversion persisted');
   check($pdo->query("SELECT body FROM ce_forum_post_revisions WHERE post_id='$post' AND revision_number=1")->fetchColumn()===$pdo->query("SELECT body FROM ce_forum_posts WHERE id='$post'")->fetchColumn(),'XF post baseline revision matches content');
   check($id('content_links',30)===$post&&$id('content_links',31)===$id('posts',31),'XF conversion is mapped and resumed');
   check($pdo->query("SELECT profile_visibility FROM ce_forum_member_preferences WHERE user_id='$user'")->fetchColumn()==='private','XF imported profile remains private');
   check($pdo->query("SELECT COUNT(*) FROM ce_forum_member_relations WHERE kind='follow'")->fetchColumn()==2,'XF follow relationships persisted for imported users');
   check($pdo->query("SELECT role_id FROM ce_forum_primary_groups WHERE user_id='$user'")->fetchColumn()===$id('groups',2),'XF source primary group remains display primary, without copied permissions');
   check($pdo->query("SELECT permissions FROM ce_roles WHERE id='".$id('groups',3)."'")->fetchColumn()==='[]','XF administrator group imported without administrator privileges');
   check(ForumMigrationEngine::mapping($db,$job,'follows',3)['mapping']['reason']==='parent_not_imported','XF follows of unmapped account quarantined');

   check($id('reactions',100)===$post&&$id('reactions',101)===$id('posts',31),'XF standard reactions mapped');
   $reactionRows=$pdo->query("SELECT reaction_type FROM ce_forum_reactions WHERE post_id='$post'")->fetchAll(PDO::FETCH_COLUMN);
   check($reactionRows===['like'],'XF actual like row stored');
   foreach([102=>'self_reaction',103=>'post_or_user_not_imported',104=>'unsupported_reaction_type',105=>'post_or_user_not_imported'] as $original=>$reason){
    $entry=ForumMigrationEngine::mapping($db,$job,'reactions',$original)['mapping'];
    check($entry['outcome']==='skipped'&&$entry['reason']===$reason,"XF rejected reaction $original ($reason)");
   }
   check($pdo->query("SELECT COUNT(*) FROM ce_forum_reactions WHERE post_id='".$id('posts',31)."'")->fetchColumn()==1,'XF love reaction stored on second post');
  }else check((int)$report['job']['phase']===11,"$platform completed legacy phases without renumbering");
  check($report['relation_rows']===['watches'=>2,'board_watches'=>1,'votes'=>2],"$platform polls and subscriptions");
  check($pdo->query("SELECT COUNT(*) FROM ce_user_roles WHERE user_id='$user'")->fetchColumn()==3,"$platform group membership without privileges");
  $engine->step(1);check($pdo->query("SELECT COUNT(*) FROM ce_forum_posts WHERE thread_id='$thread'")->fetchColumn()==2,"$platform completed run idempotent");
  $source->rollBack();unset($source,$adapter,$engine);
 }
 check(count(array_unique($jobs))===3,'platform-specific snapshot identity');
 // Real ACP authorization and persisted reports, not a simulated API response.
 $pdo->exec("INSERT INTO ce_users(id,email,name,password_hash) VALUES('operator','operator@example.test','Operator','test-hash'),('delegate','delegate@example.test','Delegate','test-hash')");
 $pdo->exec("INSERT INTO ce_roles(id,handle,name,permissions) VALUES('operator-role','import-test-admin','Operator','[\"*\"]'),('delegate-role','import-test-delegate','Delegate','[\"forum:acp:settings\"]')");
 $pdo->exec("INSERT INTO ce_user_roles(user_id,role_id) VALUES('operator','operator-role'),('delegate','delegate-role')");
 $tx=new \CajeerEngine\Forum\Service\ForumTransaction($db,new \CajeerEngine\Database\Repository\AuditLogRepository($db));
 $acp=new \CajeerEngine\Forum\Service\ForumAdminService($db,$tx);$actor=new \CajeerEngine\Forum\Security\ForumAccessPolicy('operator',['*']);
 check(count($acp->listing($actor,['view'=>'imports'])['items'])===3,'ACP reads actual migration ledger');
 check($acp->listing($actor,['view'=>'import_job','job'=>$jobs[0]])['job']['platform']==='xenforo','ACP reads actual job report');
 try{$acp->listing(new \CajeerEngine\Forum\Security\ForumAccessPolicy('delegate',['forum:acp:settings']),['view'=>'imports']);throw new LogicException('Delegate read migration jobs');}catch(\CajeerEngine\Forum\ForumException $e){check($e->status===403,'ACP delegate denied');}

 check((new \CajeerEngine\Forum\ForumIntegrityCheck($db))->check()['ok'],'target forum integrity');
 check((new \CajeerEngine\Forum\Service\ForumSearchMaintenance($db))->check()['ok'],'target FTS integrity');
 check($pdo->query('SELECT COUNT(*) FROM ce_forum_extension_events')->fetchColumn()==0&&$pdo->query('SELECT COUNT(*) FROM ce_forum_mail_delivery')->fetchColumn()==0,'no historical event/mail dispatch');
 $source=new PDO('sqlite:'.$tmp.'/source.sqlite');$source->exec('ALTER TABLE xf_attachment_data DROP COLUMN file_key');$adapter=new MultiForumSource($source,'xf_','xenforo','2.2.15','legacy');
 check($adapter->batch('attachments',0,40,1)[0]['physical_filename']==='1/1001-'.str_repeat('a',32).'.data','XF 2.2 legacy file hash');$source->rollBack();unset($adapter,$source);
 $source=new PDO('sqlite:'.$tmp.'/source.sqlite');$source->exec('UPDATE mybb_forums SET pid=15 WHERE fid=10');
 try{new MultiForumSource($source,'mybb_','mybb','1.8.39','cycle');throw new LogicException('Cycle accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'cyclic'),'cyclic tree rejected');}
}finally{unset($engine,$adapter,$source,$db,$pdo);foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($tmp);}
