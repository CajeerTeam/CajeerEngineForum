<?php
/** Real PDO/SQLite target-schema regression for incomplete, corrupt, and resumed imports. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\Migration\ForumImportIntegrity;
use CajeerEngine\Runtime\ConfigRepository;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($file))require $file;
    }
});
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    fwrite(STDERR,"PDO SQLite required for import hardening integration\n");exit(1);
}
$dir=sys_get_temp_dir().'/forum-hardening-'.bin2hex(random_bytes(8));
if(!mkdir($dir,0700))throw new RuntimeException('Unable to initialize test snapshot');
try{
    putenv('APP_ENV=testing');putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$dir.'/target.sqlite');
    $db=new DatabaseManager(new ConfigRepository($root));$pdo=$db->connection();
    (new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');
    $u='a0000000-0000-4000-8000-000000000001';$v='a0000000-0000-4000-8000-000000000002';
    $category='a0000000-0000-4000-8000-000000000003';$board='a0000000-0000-4000-8000-000000000004';
    $conversation='a0000000-0000-4000-8000-000000000005';$message='a0000000-0000-4000-8000-000000000006';
    $attachment='a0000000-0000-4000-8000-000000000007';$job=str_repeat('b',64);
    foreach([[$u,'a@source.test'],[$v,'b@source.test']] as [$id,$email]){
        $q=$pdo->prepare("INSERT INTO ce_users(id,email,name,password_hash,status,disabled_at) VALUES(?,?,?,'secret','disabled',CURRENT_TIMESTAMP)");
        $q->execute([$id,$email,$email]);
    }
    $pdo->prepare("INSERT INTO ce_forum_categories(id,name,description,visibility) VALUES(?,?,'','hidden')")->execute([$category,'Archive']);
    $pdo->prepare("INSERT INTO ce_forum_boards(id,category_id,name,description,visibility,state) VALUES(?,?,?,'','hidden','readonly')")->execute([$board,$category,'Archive board']);
    $pdo->prepare('INSERT INTO ce_forum_import_jobs(id,source_version,category_id,backup_id,plan_json,created_at,updated_at) VALUES(?,?,?,?,?,1,1)')
        ->execute([$job,'5.0.0',$category,'test',json_encode(['pipeline_version'=>8])]);
    $map=$pdo->prepare("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome) VALUES(?,?,?,?,'imported')");
    $map->execute([$job,'users',1,$u]);$map->execute([$job,'users',2,$v]);$map->execute([$job,'forums',1,$board]);
    $check=static function(bool $test,string $name):void{if(!$test)throw new RuntimeException('FAILED '.$name);echo 'PASS '.$name."\n";};
    $report=ForumImportIntegrity::inspect($db,$job);
    $check($report['ok'] && !$report['complete'],'incomplete, quarantined import reports safe but not accepted');
    $pdo->prepare("UPDATE ce_forum_boards SET visibility='public' WHERE id=?")->execute([$board]);
    $check(!ForumImportIntegrity::inspect($db,$job)['ok'],'premature public exposure blocked');
    $pdo->prepare("UPDATE ce_forum_boards SET visibility='hidden' WHERE id=?")->execute([$board]);
    $pdo->prepare("UPDATE ce_users SET status='active',disabled_at=NULL WHERE id=?")->execute([$u]);
    $check(!ForumImportIntegrity::inspect($db,$job)['ok'],'premature user activation blocked');
    $pdo->prepare("UPDATE ce_users SET status='disabled',disabled_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$u]);
    $pdo->prepare("INSERT INTO ce_forum_conversations(id,owner_id,title,kind,created_at,updated_at) VALUES(?,?,?,'direct',1,1)")
        ->execute([$conversation,$u,'Private']);
    $pdo->prepare('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence,active) VALUES(?,?,1,0,1)')->execute([$conversation,$u]);
    $map->execute([$job,'inv_conversations',2,$conversation]);
    $check(ForumImportIntegrity::inspect($db,$job)['ok'],'single-recipient self conversation remains private');
    $pdo->prepare('UPDATE ce_forum_conversations SET owner_id=? WHERE id=?')->execute([$v,$conversation]);
    $check(!ForumImportIntegrity::inspect($db,$job)['ok'],'conversation owner outside active audience blocked');
    $pdo->prepare('UPDATE ce_forum_conversations SET owner_id=? WHERE id=?')->execute([$u,$conversation]);
    $pdo->prepare('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence,active) VALUES(?,?,1,0,1)')->execute([$conversation,$v]);
    $pdo->prepare("INSERT INTO ce_forum_conversation_messages(id,conversation_id,author_id,message_sequence,body,created_at) VALUES(?,?,?,1,'secret',1)")
        ->execute([$message,$conversation,$u]);
    $map->execute([$job,'inv_messages',3,$message]);
    $check(ForumImportIntegrity::inspect($db,$job)['ok'],'correct private conversation and active author accepted');
    $pdo->prepare('UPDATE ce_forum_conversation_messages SET author_id=? WHERE id=?')->execute([$v,$message]);
    $pdo->prepare('UPDATE ce_forum_conversation_participants SET active=0 WHERE conversation_id=? AND user_id=?')->execute([$conversation,$v]);
    $check(!ForumImportIntegrity::inspect($db,$job)['ok'],'inactive author and altered audience blocked');
    $pdo->prepare('UPDATE ce_forum_conversation_participants SET active=1 WHERE conversation_id=? AND user_id=?')->execute([$conversation,$v]);
    $pdo->prepare('UPDATE ce_forum_conversation_messages SET author_id=? WHERE id=?')->execute([$u,$message]);
    $data='private attachment bytes';
    $pdo->prepare('INSERT INTO ce_forum_attachments(id,owner_id,message_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,1,0)')
        ->execute([$attachment,$u,$message,'note.txt','text/plain',strlen($data),str_repeat('0',64),base64_encode($data)]);
    $map->execute([$job,'conversation_attachments',9,$attachment]);
    $check(ForumImportIntegrity::inspect($db,$job)['ok'],'shallow verification checks private file ownership');
    $check(!ForumImportIntegrity::inspect($db,$job,true)['ok'],'deep verification detects corrupt attachment digest');
    $pdo->prepare('UPDATE ce_forum_attachments SET sha256=? WHERE id=?')->execute([hash('sha256',$data),$attachment]);
    $check(ForumImportIntegrity::inspect($db,$job,true)['ok'],'valid attachment hash and size accepted');
    $pdo->prepare('UPDATE ce_forum_attachments SET size_bytes=size_bytes+1 WHERE id=?')->execute([$attachment]);
    $check(!ForumImportIntegrity::inspect($db,$job,true)['ok'],'deep verification detects wrong byte count');
    $pdo->prepare('UPDATE ce_forum_attachments SET size_bytes=? WHERE id=?')->execute([strlen($data),$attachment]);
    $pdo->prepare("UPDATE ce_forum_import_jobs SET state='complete' WHERE id=?")->execute([$job]);
    $check(ForumImportIntegrity::inspect($db,$job,true)['complete'],'completed job correctly indicated');
    echo "Import hardening integration PASS\n";
} finally {
    unset($pdo,$db);
    foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
    @rmdir($dir);
}
