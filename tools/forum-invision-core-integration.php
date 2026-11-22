<?php
/** Executable import regression: real PDO source/target, rollback, resume and quarantined access. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,InvisionSource,InvisionContent};
use CajeerEngine\Runtime\ConfigRepository;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($file))require $file;
    }
});
$assertions=0;
$check=static function(bool $result,string $label)use(&$assertions):void{
    if(!$result)throw new RuntimeException('FAIL '.$label);
    ++$assertions;echo 'PASS '.$label."\n";
};
$check(InvisionContent::plain('<p>Hello <b>there</b> &amp; <script>bad()</script>friends</p>')==="Hello there & friends",'safe HTML-to-text conversion');
$check(InvisionContent::plain(str_repeat('x',1048577))===null,'oversized post rejected without truncation');
$check(InvisionContent::plain("bad\xFF")===null,'invalid UTF8 rejected');
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    fwrite(STDERR,"PDO SQLite required for real Invision core migration integration\n");exit(1);
}
$tmp=sys_get_temp_dir().'/invision-core-'.bin2hex(random_bytes(8));
if(!mkdir($tmp,0700))throw new RuntimeException('Failed to create import test directory');
try{
    $source=new PDO('sqlite:'.$tmp.'/invision.sqlite');
    $source->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $source->exec(file_get_contents($root.'/tools/forum-tests/invision_fixture.sql'));
    $source->exec("UPDATE ips_forums_posts SET post='<p>Hello <b>World</b> &amp; <script>evil()</script>friends</p>' WHERE pid=30");
    $source->exec("ALTER TABLE ips_forums_forums ADD COLUMN sub_can_post INTEGER DEFAULT 1");
    $source->exec("UPDATE ips_forums_forums SET sub_can_post=0 WHERE id=10");
    $source->exec("ALTER TABLE ips_core_members ADD COLUMN mgroup_others TEXT DEFAULT ''");
    $source->exec("INSERT INTO ips_core_groups VALUES(4,'Moderators')");
    $source->exec("UPDATE ips_core_members SET mgroup_others='4' WHERE member_id=1");
    $source->exec("INSERT INTO ips_forums_topics VALUES(21,11,2,'Hidden topic',17000003,0)");
    $source->exec("INSERT INTO ips_forums_posts VALUES(32,21,2,17000004,'Private post',1)");
    $source=null;
    putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$tmp.'/target.sqlite');putenv('APP_ENV=testing');
    $db=new DatabaseManager(new ConfigRepository($root));$target=$db->connection();
    (new MigrationRunner($target,$root.'/migrations'))->run('sqlite');
    $open=static fn():PDO=>new PDO('sqlite:'.$tmp.'/invision.sqlite');
    $source=$open();$adapter=new InvisionSource($source,null,null,'frozen-test-4');
    $engine=new ForumMigrationEngine($db,$adapter);
    $plan=$engine->snapshotPlan();
    $check($plan['pipeline_version']===6&&$plan['groups']['count']===2&&$plan['posts']['count']===3,'v6 plan uses exact source totals');
    $phases=(new ReflectionClass($engine))->getMethod('phaseNames')->invoke($engine,$plan);
    $check($phases===['groups','users','forums','topics','posts','finalize'],'v6 core phase sequence without unsupported phases');
    $check($adapter->batch('forums',0,2,10)[0]['forum_id']===10,'parent-first tree ordering');
    $check($adapter->memberships(1)===[3,4],'primary and secondary groups normalized');
    $job=$engine->start('test-backup')['job']['id'];
    $target->exec("CREATE TRIGGER invision_fail BEFORE INSERT ON ce_forum_import_map BEGIN SELECT RAISE(ABORT,'injected core rollback'); END");
    try{$engine->step(2);throw new LogicException('Expected injected transaction failure');}catch(PDOException){}
    $check((int)$target->query('SELECT COUNT(*) FROM ce_forum_import_map')->fetchColumn()===0 && (int)$engine->progress()['job']['cursor_id']===0,'batch and checkpoint rolled back atomically');
    $target->exec('DROP TRIGGER invision_fail');$engine->step(2);
    $source->rollBack();unset($source,$adapter,$engine);
    $source=$open();$adapter=new InvisionSource($source,null,'4.7.24','frozen-test-4');$engine=new ForumMigrationEngine($db,$adapter);
    $check($engine->matchesRecordedPlan($plan),'frozen source resumes with matching schema ledger');
    for($i=0;$i<80;$i++)if($engine->step(2)['job']['state']==='complete')break;
    $check($engine->status()['job']['state']==='complete','complete six-phase transactional core import');
    $id=static fn(string $phase,int $source)=>ForumMigrationEngine::mapping($db,$job,$phase,$source)['mapping']['target_id']??null;
    $check($id('groups',3)!==null&&$id('groups',4)!==null&&$id('users',1)!==null&&$id('users',2)!==null,'real source members/groups migrated');
    $check($id('forums',10)!==null&&$id('forums',11)!==null&&$id('topics',20)!==null&&$id('posts',30)!==null,'forum tree, topic and post migrated');
    $check($id('topics',21)===null&&$id('posts',32)===null,'unapproved topic and private post excluded');
    $stmt=$target->prepare('SELECT body,body_format FROM ce_forum_posts WHERE id=?');$stmt->execute([$id('posts',30)]);$body=$stmt->fetch(PDO::FETCH_ASSOC);
    $check($body['body']==='Hello World & friends'&&$body['body_format']==='plain','post content safely converted and actually stored');
    $stmt=$target->prepare('SELECT status,disabled_at FROM ce_users WHERE id=?');$stmt->execute([$id('users',1)]);$u=$stmt->fetch(PDO::FETCH_ASSOC);
    $check($u['status']==='disabled'&&$u['disabled_at']!==null,'imported account is disabled until recovery');
    $stmt=$target->prepare('SELECT visibility,state,node_type FROM ce_forum_boards WHERE id=?');$stmt->execute([$id('forums',10)]);$b=$stmt->fetch(PDO::FETCH_ASSOC);
    $check($b['visibility']==='hidden'&&$b['state']==='readonly'&&$b['node_type']==='container','category quarantine and node type preserved');
    $stmt=$target->prepare('SELECT permissions FROM ce_roles WHERE id=?');$stmt->execute([$id('groups',4)]);
    $check($stmt->fetchColumn()==='[]','source moderator group never grants admin privileges');
    $stmt=$target->prepare('SELECT COUNT(*) FROM ce_user_roles WHERE user_id=? AND role_id=?');$stmt->execute([$id('users',1),$id('groups',4)]);
    $check((int)$stmt->fetchColumn()===1,'secondary group membership persists');
    $engine->step(2);
    $check((int)$target->query('SELECT COUNT(*) FROM ce_forum_posts')->fetchColumn()===2,'completed import is idempotent');
    $source->rollBack();unset($source,$adapter,$engine);
    $change=$open();$change->exec('ALTER TABLE ips_forums_posts ADD COLUMN changed_after_snapshot INTEGER');unset($change);
    $source=$open();$engine=new ForumMigrationEngine($db,new InvisionSource($source,null,null,'frozen-test-4'));
    $check(!$engine->matchesRecordedPlan($plan),'source schema change invalidates recorded checkpoint');
    echo "Invision 1.1.8 core integration: $assertions PASS\n";
}finally{
    unset($engine,$adapter,$source,$db,$target);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item)
        $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());
    rmdir($tmp);
}
