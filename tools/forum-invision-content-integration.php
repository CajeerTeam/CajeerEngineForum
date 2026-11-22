<?php
/** Real PDO/SQLite integration acceptance: IPS4/5 snapshots, atomic media and rich conversion. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,InvisionSource};
use CajeerEngine\Runtime\ConfigRepository;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($file))require $file;
    }
});
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    fwrite(STDERR,"PDO SQLite required for Invision 1.1.9 integration\n");exit(1);
}
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";$checks++;
};
$tmp=sys_get_temp_dir().'/invision-rich-'.bin2hex(random_bytes(8));
if(!mkdir($tmp,0700)||!mkdir($tmp.'/uploads',0700))throw new RuntimeException('Cannot create test snapshot');
try{
    file_put_contents($tmp.'/uploads/file.txt','Hello frozen IPS attachment');
    $source=new PDO('sqlite:'.$tmp.'/source.sqlite');
    $source->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $source->exec(file_get_contents($root.'/tools/forum-tests/invision_fixture.sql'));
    $source->exec('ALTER TABLE ips_core_attachments ADD COLUMN attach_member_id INTEGER');
    $source->exec('ALTER TABLE ips_core_attachments ADD COLUMN attach_filesize INTEGER');
    $source->exec('ALTER TABLE ips_core_attachments ADD COLUMN attach_date INTEGER');
    $source->exec("CREATE TABLE ips_core_attachments_map(attachment_id INTEGER,location TEXT,id1 INTEGER)");
    $source->exec("INSERT INTO ips_core_attachments VALUES(7,'file.txt','file.txt',1,27,17000001)");
    $source->exec("INSERT INTO ips_core_attachments_map VALUES(7,'forums_Post',30)");
    $source->exec('ALTER TABLE ips_forums_topics ADD COLUMN poll_state INTEGER DEFAULT 0');
    $source->exec('CREATE TABLE ips_core_polls(pid INTEGER,poll_question TEXT,choices TEXT)');
    $query=$source->prepare('INSERT INTO ips_core_polls VALUES(?,?,?)');
    $query->execute([42,'Choose one',json_encode(['Yes','No'],JSON_THROW_ON_ERROR)]);
    $source->exec('UPDATE ips_forums_topics SET poll_state=42 WHERE tid=20');
    $query=$source->prepare('UPDATE ips_forums_posts SET post=? WHERE pid=30');
    $query->execute(['<p>Hello <strong>World</strong> <a href="/attachment.php?id=7">Download</a><blockquote data-cid="31">Previous</blockquote></p>']);
    $source=null;
    putenv('APP_ENV=testing');putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$tmp.'/target.sqlite');
    $db=new DatabaseManager(new ConfigRepository($root));$target=$db->connection();
    (new MigrationRunner($target,$root.'/migrations'))->run('sqlite');
    $open=static fn():PDO=>new PDO('sqlite:'.$tmp.'/source.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $source=$open();$adapter=new InvisionSource($source,null,null,'v7-frozen',$tmp.'/uploads');
    $engine=new ForumMigrationEngine($db,$adapter,$tmp.'/uploads',null,7);
    $plan=$engine->snapshotPlan();
    $check(($plan['pipeline_version']??0)===7&&$plan['attachments']['count']===1&&$plan['polls']['count']===1,'v7 immutable media bounds');
    $phases=(new ReflectionClass($engine))->getMethod('phaseNames')->invoke($engine,$plan);
    $check(array_slice($phases,-3)===['attachments','polls','invision_content'],'append only phases after v6');
    $file=$adapter->contentBatch('attachments',0,$plan['attachments']['high'],10);
    $check((int)$file[0]['post_msg_id']===30 && (int)$file[0]['map_count']===1,'post media mapped by IPS relation');
    $poll=$adapter->contentBatch('polls',0,$plan['polls']['high'],10);
    $check((int)$poll[0]['topic_id']===20,'poll linked to imported topic');
    $job=$engine->start('test-backup')['job']['id'];
    for($i=0;$i<40;$i++){
        $state=$engine->progress()['job'];
        if($phases[(int)$state['phase']]==='attachments')break;
        $engine->step(1);
    }
    $check($phases[(int)$engine->progress()['job']['phase']]==='attachments','media phase reached');
    $target->exec("CREATE TRIGGER invision_fail BEFORE INSERT ON ce_forum_attachments BEGIN SELECT RAISE(ABORT,'injected media write failure'); END");
    $before=$engine->progress()['job'];
    try{$engine->step(1);throw new LogicException('Expected injected failure');}catch(PDOException){}
    $after=$engine->progress()['job'];
    $check($before['phase']===$after['phase']&&$before['cursor_id']===$after['cursor_id']&&
        (int)$target->query('SELECT COUNT(*) FROM ce_forum_attachments')->fetchColumn()===0,'file, ID map and checkpoint roll back together');
    $target->exec('DROP TRIGGER invision_fail');
    $source->rollBack();unset($source,$adapter,$engine);
    $source=$open();$adapter=new InvisionSource($source,null,null,'v7-frozen',$tmp.'/uploads');
    $engine=new ForumMigrationEngine($db,$adapter,$tmp.'/uploads',null,7);
    $check($engine->matchesRecordedPlan($plan),'v7 source fingerprint retained after restart');
    for($i=0;$i<60;$i++){
        if($engine->step(1)['job']['state']==='complete')break;
    }
    $check($engine->progress()['job']['state']==='complete','invision rich import finishes');
    $map=static fn(string $kind,int $id)=>ForumMigrationEngine::mapping($db,$job,$kind,$id)['mapping']['target_id']??null;
    $post=$map('posts',30);$attachment=$map('attachments',7);$thread=$map('topics',20);
    $check(is_string($attachment)&&is_string($post)&&is_string($thread),'post and file have target mappings');
    $q=$target->prepare('SELECT sha256,post_id,mime,file_data FROM ce_forum_attachments WHERE id=?');$q->execute([$attachment]);$saved=$q->fetch(PDO::FETCH_ASSOC);
    $check($saved['post_id']===$post&&$saved['sha256']===hash('sha256','Hello frozen IPS attachment')&&base64_decode($saved['file_data'])==='Hello frozen IPS attachment','attachment bytes and ownership stored with sha256');
    $q=$target->prepare('SELECT body,body_format FROM ce_forum_posts WHERE id=?');$q->execute([$post]);$saved=$q->fetch(PDO::FETCH_ASSOC);
    $check($saved['body_format']==='bbcode'&&str_contains($saved['body'],'[b]World[/b]')&&str_contains($saved['body'],'/api/v1/forum/attachments/'.$attachment.'/download'),'content rewritten to safe linked BBCode');
    $check((int)$target->query('SELECT COUNT(*) FROM ce_forum_polls')->fetchColumn()===1&&
        (int)$target->query('SELECT COUNT(*) FROM ce_forum_poll_options')->fetchColumn()===2,'closed poll and choices actually written');
    $check($map('invision_content',30)===$post && $map('polls',42)===$thread,'rich content and poll ledger recorded');
    $engine->step(1);
    $check((int)$target->query('SELECT COUNT(*) FROM ce_forum_attachments')->fetchColumn()===1,'completed import idempotent');
    $source->rollBack();unset($source,$adapter,$engine);
    $source=$open();$legacy=new InvisionSource($source,null,null,'v7-frozen',$tmp.'/uploads');
    $coreEngine=new ForumMigrationEngine($db,$legacy,$tmp.'/uploads',null,6);
    $old=$coreEngine->snapshotPlan();
    $check($old['pipeline_version']===6 && $coreEngine->matchesRecordedPlan($old),'legacy v6 plan remains computable and compatible');
    echo "Invision 1.1.9 integration: $checks PASS\n";
}finally{
    unset($source,$legacy,$adapter,$engine,$db,$target);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f)
        $f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());
    rmdir($tmp);
}
