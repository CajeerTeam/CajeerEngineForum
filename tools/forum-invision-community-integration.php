<?php
/** End-to-end v8: real snapshot, real target DB, real importer, failure and resume. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,InvisionSource,InvisionAcceptance};
use CajeerEngine\Runtime\ConfigRepository;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($file))require $file;
    }
});
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"PDO SQLite required for Invision v8 integration\n");exit(1);}
$passed=0;$check=static function(bool $condition,string $label)use(&$passed):void{
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    $passed++;echo 'PASS '.$label."\n";
};
$tmp=sys_get_temp_dir().'/invision-community-'.bin2hex(random_bytes(8));
if(!mkdir($tmp,0700))throw new RuntimeException('Cannot create temporary test directory');
try{
    $src=new PDO('sqlite:'.$tmp.'/source.sqlite');$src->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $src->exec(file_get_contents($root.'/tools/forum-tests/invision_fixture.sql'));
    $src->exec("ALTER TABLE ips_core_members ADD COLUMN pp_about_me TEXT DEFAULT ''");
    $src->exec("ALTER TABLE ips_core_members ADD COLUMN signature TEXT DEFAULT ''");
    $src->exec("UPDATE ips_core_members SET pp_about_me='<p>Bio &amp; welcome</p>',signature='Hello' WHERE member_id=1");
    $src->exec("DROP TABLE ips_core_message_topics");
    $src->exec("DROP TABLE ips_core_message_posts");
    $src->exec("DROP TABLE ips_core_follow");
    $src->exec("CREATE TABLE ips_core_reputation_index(id INTEGER,member_id INTEGER,type_id INTEGER,rep_rating INTEGER,type TEXT,app TEXT)");
    $src->exec("INSERT INTO ips_core_reputation_index VALUES (2,2,30,1,'pid','forums'),(3,1,30,1,'pid','forums')");
    $src->exec("CREATE TABLE ips_core_follow(follow_id INTEGER,follow_app TEXT,follow_area TEXT,follow_rel_id INTEGER,follow_member_id INTEGER,follow_is_anon INTEGER DEFAULT 0)");
    $src->exec("INSERT INTO ips_core_follow VALUES (10,'forums','topic',20,2,0),(11,'core','member',1,2,0),(12,'forums','topic',20,1,1)");
    $src->exec("CREATE TABLE ips_core_message_topics(mt_id INTEGER,mt_title TEXT,mt_starter_id INTEGER,mt_date INTEGER)");
    $src->exec("CREATE TABLE ips_core_message_topic_user_map(map_topic_id INTEGER,map_user_id INTEGER,map_user_active INTEGER,map_left_time INTEGER,map_user_banned INTEGER)");
    $src->exec("CREATE TABLE ips_core_message_posts(msg_id INTEGER,msg_topic_id INTEGER,msg_author_id INTEGER,msg_post TEXT,msg_date INTEGER)");
    $src->exec("INSERT INTO ips_core_message_topics VALUES (60,'Private Alice-Bob',1,17000001),(61,'Deleted chat',1,17000002)");
    $src->exec("INSERT INTO ips_core_message_topic_user_map VALUES (60,1,1,0,0),(60,2,1,0,0),(61,1,0,4,0),(61,2,1,0,0)");
    $src->exec("INSERT INTO ips_core_message_posts VALUES (70,60,1,'<p>Private <b>hello</b></p>',17000002),(71,60,2,'<p>Reply</p>',17000003),(72,61,2,'<p>Forbidden</p>',17000004)");
    $src=null;
    putenv('APP_ENV=testing');putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$tmp.'/target.sqlite');
    $db=new DatabaseManager(new ConfigRepository($root));$target=$db->connection();
    (new MigrationRunner($target,$root.'/migrations'))->run('sqlite');
    $open=static fn()=>new PDO('sqlite:'.$tmp.'/source.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $src=$open();$adapter=new InvisionSource($src,null,null,'v8-snapshot');
    $engine=new ForumMigrationEngine($db,$adapter);
    $plan=$engine->snapshotPlan();$check($plan['pipeline_version']===8 && $plan['inv_messages']['count']===3 && $plan['inv_conversations']['count']===2,'v8 source plan counts community rows');
    $names=(new ReflectionClass($engine))->getMethod('phaseNames')->invoke($engine,$plan);
    $check(count($names)===15 && array_slice($names,-6)===InvisionSource::COMMUNITY_PHASES,'append-only v8 phases');
    $job=$engine->start('test-backup')['job']['id'];
    // Fail in a real transactional batch before any community ledger row is committed.
    for($i=0;$i<100;$i++){
        $state=$engine->progress()['job'];if($state['state']==='complete')break;
        if($names[(int)$state['phase']]==='inv_profiles')break;
        $engine->step(2);
    }
    $check($names[(int)$engine->progress()['job']['phase']]==='inv_profiles','core and rich content phases finished');
    $target->exec("CREATE TRIGGER invision_community_failure BEFORE INSERT ON ce_forum_import_map WHEN NEW.kind='inv_profiles' BEGIN SELECT RAISE(ABORT,'deliberate rollback'); END");
    try{$engine->step(2);throw new LogicException('Expected transactional failure');}catch(PDOException){}
    $check((int)$target->query("SELECT COUNT(*) FROM ce_forum_member_profiles")->fetchColumn()===0 &&
        (int)$target->query("SELECT COUNT(*) FROM ce_forum_import_map WHERE kind='inv_profiles'")->fetchColumn()===0,
        'failed community batch rolls back target rows and ledger');
    $target->exec('DROP TRIGGER invision_community_failure');
    $src->rollBack();unset($src,$adapter,$engine);
    $src=$open();$adapter=new InvisionSource($src,null,null,'v8-snapshot');
    $engine=new ForumMigrationEngine($db,$adapter);
    $check($engine->matchesRecordedPlan($plan),'resume from frozen source and matching v8 ledger');
    for($i=0;$i<150;$i++)if($engine->step(2)['job']['state']==='complete')break;
    $check($engine->progress()['job']['state']==='complete','v8 community import completes');
    $map=static fn(string $kind,int $old)=>ForumMigrationEngine::mapping($db,$job,$kind,$old)['mapping']['target_id']??null;
    $alice=$map('users',1);$bob=$map('users',2);$post=$map('posts',30);
    $check(is_string($map('inv_profiles',1))&&$map('inv_profiles',1)===$alice,'profile row imported');
    $q=$target->prepare('SELECT bio FROM ce_forum_member_profiles WHERE user_id=?');$q->execute([$alice]);
    $check($q->fetchColumn()==='Bio & welcome','profile HTML converted to safe text');
    $q=$target->prepare("SELECT profile_visibility FROM ce_forum_member_preferences WHERE user_id=?");$q->execute([$alice]);
    $check($q->fetchColumn()==='private','imported user profile remains private');
    $q=$target->prepare('SELECT reaction_type FROM ce_forum_reactions WHERE post_id=? AND user_id=?');$q->execute([$post,$bob]);
    $check($q->fetchColumn()==='like' && $map('inv_reactions',3)===null,'standard positive reaction imported, self reaction refused');
    $check($map('inv_follow',10)===$bob&&$map('inv_follow',11)===$bob&&$map('inv_follow',12)===null,'subscriptions and private/anonymous follow policy enforced');
    $chat=$map('inv_conversations',60);$check(is_string($chat)&&$map('inv_conversations',61)===null,'deleted recipient conversation not restored');
    $q=$target->prepare('SELECT COUNT(*) FROM ce_forum_conversation_participants WHERE conversation_id=?');$q->execute([$chat]);
    $check((int)$q->fetchColumn()===2,'private audience imported exactly');
    $message=$map('inv_messages',70);$q=$target->prepare('SELECT body,body_format FROM ce_forum_conversation_messages WHERE id=?');$q->execute([$message]);
    $body=$q->fetch(PDO::FETCH_ASSOC);
    $check($body['body_format']==='bbcode'&&str_contains($body['body'],'[b]hello[/b]')&&$map('inv_messages',72)===null,'private content converted and deleted thread omitted');
    $check((int)$target->query('PRAGMA foreign_key_check')->fetchColumn()===0,'target has no broken foreign keys');
    // Read-only full-source acceptance must fail until the operator approves the exact skip ledger.
    $acceptance=new InvisionAcceptance($db,$adapter,$engine);
    $unapproved=$acceptance->check($job);
    $check(!$unapproved['ok'] && in_array('unapproved_skipped_records',$unapproved['violations'],true),
        'unapproved source omissions block acceptance');
    $accepted=$acceptance->check($job,$unapproved['skip_sha256']);
    $check($accepted['ok'] && $accepted['complete'] && $accepted['source_rows']['inv_messages']===3,
        'full source and target reconciliation passes with reviewed skips: '.implode(', ',$accepted['violations']));
    $target->prepare('UPDATE ce_forum_conversation_messages SET body=? WHERE id=?')->execute(['TAMPERED',$message]);
    $tampered=$acceptance->check($job,$unapproved['skip_sha256']);
    $check(!$tampered['ok'] && in_array('source_body_mismatch:inv_messages:70',$tampered['violations'],true),
        'mutated private content cannot pass source acceptance');
    $target->prepare('UPDATE ce_forum_conversation_messages SET body=? WHERE id=?')->execute([$body['body'],$message]);
    $target->prepare('DELETE FROM ce_forum_conversation_participants WHERE conversation_id=? AND user_id=?')->execute([$chat,$bob]);
    $tampered=$acceptance->check($job,$unapproved['skip_sha256']);
    $check(!$tampered['ok'] && in_array('private_recipient_mismatch:60',$tampered['violations'],true),
        'missing private participant cannot pass source acceptance');

    $src->rollBack();
    echo "Invision v8 community integration: $passed PASS\n";
}finally{
    unset($engine,$adapter,$src,$db,$target);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item)
        $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());
    rmdir($tmp);
}
