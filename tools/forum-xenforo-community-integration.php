<?php
/** Real v4 XenForo snapshot -> PDO target -> rollback/restart acceptance. Mandatory in CI. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,MultiForumSource};
use CajeerEngine\Forum\Security\ProfileImage;
use CajeerEngine\Runtime\ConfigRepository;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $name) use($root):void {
    if(str_starts_with($name,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($name,13)).'.php';
        if(is_file($file))require $file;
    }
});
if(!in_array('sqlite',PDO::getAvailableDrivers(),true) || !function_exists('imagecreatetruecolor')){
    fwrite(STDERR,"pdo_sqlite and GD are mandatory for community importer integration\n");exit(1);
}
$count=0;
$check=static function(bool $valid,string $message)use(&$count):void{
    if(!$valid)throw new RuntimeException('FAIL '.$message);
    ++$count;echo 'PASS '.$message."\n";
};
$tmp=sys_get_temp_dir().'/xf-community-'.bin2hex(random_bytes(8));
if(!mkdir($tmp,0700))throw new RuntimeException('Temporary fixture directory unavailable');
try{
    $source=new PDO('sqlite:'.$tmp.'/source.sqlite');
    $source->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $source->exec(file_get_contents($root.'/tools/forum-tests/multi_forum_fixture.sql'));
    $source->exec('UPDATE xf_user SET avatar_date=1700000000');
    $source=null;
    $avatars=$tmp.'/avatars';
    mkdir($avatars.'/o/0',0700,true);
    $canvas=imagecreatetruecolor(32,32);
    imagefilledrectangle($canvas,0,0,31,31,imagecolorallocate($canvas,60,90,120));
    if(!imagejpeg($canvas,$avatars.'/o/0/1.jpg',92))throw new RuntimeException('Cannot prepare avatar');
    imagedestroy($canvas);
    putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$tmp.'/target.sqlite');putenv('APP_ENV=testing');
    $db=new DatabaseManager(new ConfigRepository($root));$target=$db->connection();
    (new MigrationRunner($target,$root.'/migrations'))->run('sqlite');
    $target->exec("INSERT INTO ce_users(id,email,name,password_hash) VALUES('existing','existing@example.test','Existing','hash')");
    $open=static fn()=>new PDO('sqlite:'.$tmp.'/source.sqlite');
    $source=$open();$adapter=new MultiForumSource($source,'xf_','xenforo','2.3.0','community-test');
    $engine=new ForumMigrationEngine($db,$adapter,null,$avatars,4);
    $plan=$engine->snapshotPlan();
    $check($plan['pipeline_version']===4&&$plan['avatars']['count']===3&&$plan['follows']['count']===3,'community phases freeze real source counts');
    $phases=(new ReflectionClass($engine))->getMethod('phaseNames')->invoke($engine,$plan);
    $check(count($phases)===16&&array_slice($phases,-3)===['avatars','follows','content_links'],'new jobs append two community phases before content linking');
    $check(count((new ReflectionClass($engine))->getMethod('phaseNames')->invoke($engine,$engine->snapshotPlan(true,3)))===14,'v3 checkpoint sequence preserved');
    try{(new ForumMigrationEngine($db,$adapter,null,null,4))->start('should-not-create-backup');throw new LogicException('Missing avatar root accepted');}
    catch(RuntimeException $e){$check(str_contains($e->getMessage(),'avatar'),'avatar snapshot is mandatory before any target changes');}
    $job=$engine->start('test-backup')['job']['id'];
    for($i=0;$i<160;$i++){
        if((int)$engine->progress()['job']['phase']===13)break;
        $engine->step(10);
    }
    $check((int)$engine->progress()['job']['phase']===13,'avatar phase reached after base content and reactions');
    $target->exec("CREATE TRIGGER reject_avatar BEFORE INSERT ON ce_forum_profile_images BEGIN SELECT RAISE(ABORT,'avatar transaction rollback'); END");
    try{$engine->step(10);throw new LogicException('Injected database error not raised');}catch(PDOException){}
    $check((int)$engine->progress()['job']['cursor_id']===0&&(int)$target->query('SELECT COUNT(*) FROM ce_forum_profile_images')->fetchColumn()===0,'avatar write and checkpoint both roll back');
    $target->exec('DROP TRIGGER reject_avatar');
    $source->rollBack();unset($engine,$source,$adapter);
    $source=$open();$adapter=new MultiForumSource($source,'xf_','xenforo','2.3.0','community-test');
    $engine=new ForumMigrationEngine($db,$adapter,null,$avatars,4);
    $check($engine->matchesRecordedPlan($plan),'unchanged source and community schema allow restart');
    for($i=0;$i<120;$i++)if($engine->step(10)['job']['state']==='complete')break;
    $check($engine->status()['job']['state']==='complete','real v4 migration reaches completed state');
    $id=static fn(string $kind,int $sourceId)=>ForumMigrationEngine::mapping($db,$job,$kind,$sourceId)['mapping']['target_id']??null;
    $user=$id('users',1);$bob=$id('users',2);
    $check($user!==null&&$bob!==null&&$id('avatars',1)===$user,'valid avatar mapped to imported local user');
    $stmt=$target->prepare("SELECT image_data FROM ce_forum_profile_images WHERE user_id=? AND kind='avatar'");$stmt->execute([$user]);
    $encoded=$stmt->fetchColumn();
    $bytes=is_string($encoded)?ProfileImage::decodeStored('avatar',$encoded):null;
    $check(is_string($bytes)&&strlen($bytes)>0,'avatar sanitized to real served JPEG bytes');
    $missing=ForumMigrationEngine::mapping($db,$job,'avatars',2)['mapping'];
    $conflict=ForumMigrationEngine::mapping($db,$job,'avatars',3)['mapping'];
    $check($missing['reason']==='avatar_file_missing_or_unsafe'&&$conflict['reason']==='user_not_imported','missing and unimportable avatars explicitly accounted');
    $stmt=$target->prepare("SELECT COUNT(*) FROM ce_forum_member_relations WHERE kind='follow' AND ((user_id=? AND target_id=?) OR (user_id=? AND target_id=?))");
    $stmt->execute([$user,$bob,$bob,$user]);
    $check((int)$stmt->fetchColumn()===2,'XenForo reciprocal following relationships transferred to mapped UUIDs');
    $check(ForumMigrationEngine::mapping($db,$job,'follows',3)['mapping']['reason']==='parent_not_imported','unmapped source follower explicitly skipped');
    $stmt=$target->prepare('SELECT role_id FROM ce_forum_primary_groups WHERE user_id=?');$stmt->execute([$user]);
    $check($stmt->fetchColumn()===$id('groups',2),'source primary group retained for display');
    $stmt=$target->prepare('SELECT permissions FROM ce_roles WHERE id=?');$stmt->execute([$id('groups',3)]);
    $check($stmt->fetchColumn()==='[]','source administrator group grants zero imported privileges');
    $check((int)$target->query('SELECT COUNT(*) FROM ce_forum_watches')->fetchColumn()===2&&(int)$target->query('SELECT COUNT(*) FROM ce_forum_board_watches')->fetchColumn()===1,'thread and board subscriptions unaffected');
    $check((int)$target->query('SELECT COUNT(*) FROM ce_forum_reactions')->fetchColumn()===2,'existing reaction import unaffected');
    $engine->step(10);
    $check((int)$target->query("SELECT COUNT(*) FROM ce_forum_member_relations WHERE kind='follow'")->fetchColumn()===2,'completed resume is idempotent');
    $source->rollBack();unset($engine,$source,$adapter);
    $mutator=$open();$mutator->exec('ALTER TABLE xf_user_follow ADD COLUMN unexpected_extension INTEGER');unset($mutator);
    $source=$open();$adapter=new MultiForumSource($source,'xf_','xenforo','2.3.0','community-test');
    $engine=new ForumMigrationEngine($db,$adapter,null,$avatars,4);
    $check(!$engine->matchesRecordedPlan($plan),'changed follow-table schema invalidates frozen job fingerprint');
    echo "XenForo 1.1.4 community real integration: $count PASS\n";
}finally{
    unset($engine,$adapter,$source,$target,$db);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry)
        $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());
    rmdir($tmp);
}
