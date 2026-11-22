<?php
/** Full native PDO SQLite integration: data, private media ACL, rollback, restart, frozen schema. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,MultiForumSource};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Service\ForumTransaction;
use CajeerEngine\Runtime\ConfigRepository;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void {
    if(str_starts_with($class,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($file))require $file;
    }
});
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    fwrite(STDERR,"PDO SQLite required for XenForo v5 media integration\n");exit(1);
}
$n=0;$check=static function(bool $ok,string $message)use(&$n):void {
    if(!$ok)throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message."\n";$n++;
};
$tmp=sys_get_temp_dir().'/xf-private-media-'.bin2hex(random_bytes(8));
if(!mkdir($tmp,0700))throw new RuntimeException('Cannot create test directory');
try{
    $src=new PDO('sqlite:'.$tmp.'/source.sqlite');$src->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $src->exec(file_get_contents($root.'/tools/forum-tests/multi_forum_fixture.sql'));
    // Only the active recipients of 90 may view it. 91 has an unmappable member;
    // 92's recipients have both deleted the conversation and must not be revived.
    $src->exec("INSERT INTO xf_conversation_master VALUES(91,'Unmappable private conversation',1,1600000000),(92,'Deleted conversation',1,1600000000)");
    $src->exec("INSERT INTO xf_conversation_recipient VALUES(91,1,'active'),(91,3,'active'),(92,1,'deleted'),(92,2,'deleted')");
    $src->exec("INSERT INTO xf_conversation_message VALUES(71,91,1,'Private hidden message',1600000001),(72,92,1,'Deleted hidden message',1600000002)");
    $src->prepare('UPDATE xf_conversation_message SET message=? WHERE message_id=70')->execute(['[b]Private[/b] [USER=2]Bob[/USER] [ATTACH]43[/ATTACH] [URL=https://old.example/conversations/private.90/]Own thread[/URL]']);
    $src->exec("INSERT INTO xf_attachment VALUES(43,70,'conversation_message',1001,0,1600000000)");
    $src=null;
    $files=$tmp.'/attachments';mkdir($files.'/1',0700,true);
    file_put_contents($files.'/1/1001-'.str_repeat('b',32).'.data','hello world!');
    putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$tmp.'/target.sqlite');putenv('APP_ENV=testing');putenv('APP_URL=https://forum.test');
    $db=new DatabaseManager(new ConfigRepository($root));$target=$db->connection();
    (new MigrationRunner($target,$root.'/migrations'))->run('sqlite');
    $target->exec("INSERT INTO ce_users(id,email,name,password_hash) VALUES('existing','existing@example.test','Existing','hash')");
    $open=static fn():PDO=>new PDO('sqlite:'.$tmp.'/source.sqlite');
    $source=$open();$adapter=new MultiForumSource($source,'xf_','xenforo','2.3.0','private-v5','https://old.example');
    $planSource=$adapter->mediaPlan();
    $check($planSource['conversations']['count']===3 && $planSource['conversation_messages']['count']===3 && $planSource['conversation_attachments']['count']===2,'frozen private content is counted without reading file payloads');
    $noFiles=new ForumMigrationEngine($db,$adapter);
    try{$noFiles->start('test-backup');throw new LogicException('Files not required');}
    catch(RuntimeException $e){$check(str_contains($e->getMessage(),'--files'),'private media snapshot mandatory before starting job');}
    $engine=new ForumMigrationEngine($db,$adapter,$files);
    $frozen=$engine->snapshotPlan();
    $check(($frozen['pipeline_version']??null)===5&&preg_match('/\A[a-f0-9]{64}\z/D',$frozen['source_media_schema_sha256'])===1,'new v5 job pins actual private source schema');
    $job=$engine->start('test-backup')['job']['id'];
    for($i=0;$i<250;$i++){
        $state=$engine->progress()['job'];
        if((int)$state['phase']>=18)break;
        $engine->step(25);
    }
    $state=$engine->progress()['job'];
    $check((int)$state['phase']===18 && $state['state']!=='complete','real pipeline reaches private attachment phase');
    // First attachment references a non-existent conversation message: record a skip.
    $engine->step(1);
    $before=$engine->progress()['job'];
    $check((int)$before['cursor_id']===41,'missing private attachment recorded before live attachment');
    $target->exec("CREATE TRIGGER deny_private_attachment BEFORE INSERT ON ce_forum_attachments WHEN NEW.message_id IS NOT NULL BEGIN SELECT RAISE(ABORT,'test failure'); END");
    try{$engine->step(1);throw new LogicException('Injected failure ignored');}catch(PDOException){}
    $after=$engine->progress()['job'];
    $check($after['cursor_id']===$before['cursor_id']&&(int)$target->query('SELECT COUNT(*) FROM ce_forum_attachments WHERE message_id IS NOT NULL')->fetchColumn()===0,'failed private file copy rolls back both file and checkpoint');
    $target->exec('DROP TRIGGER deny_private_attachment');
    $source->rollBack();unset($engine,$adapter,$source);
    $source=$open();$adapter=new MultiForumSource($source,'xf_','xenforo','2.3.0','private-v5','https://old.example');
    $engine=new ForumMigrationEngine($db,$adapter,$files);
    $check($engine->matchesRecordedPlan($frozen),'same snapshot allows safe resume');
    for($i=0;$i<250;$i++)if($engine->step(25)['job']['state']==='complete')break;
    $check($engine->progress()['job']['state']==='complete','real v5 import completes after an interrupted attachment');
    $mapping=static fn(string $kind,int $id):?array=>ForumMigrationEngine::mapping($db,$job,$kind,$id)['mapping'];
    $conv=$mapping('conversations',90)['target_id'];$message=$mapping('conversation_messages',70)['target_id'];
    $attachment=$mapping('conversation_attachments',43)['target_id'];
    $check($conv!==null&&$message!==null&&$attachment!==null,'real source conversation, message and file map to target UUIDs');
    $check($mapping('conversations',91)['reason']==='active_recipient_not_imported'&&$mapping('conversations',92)['reason']==='no_active_recipients','incomplete/deleted private audiences are explicitly skipped');
    $check($mapping('conversation_messages',71)['target_id']===null&&$mapping('conversation_messages',72)['target_id']===null,'orphan private message bodies never imported');
    $check($mapping('conversation_attachments',41)['target_id']===null,'unassociated source private attachment not exposed');
    $alice=$mapping('users',1)['target_id'];$bob=$mapping('users',2)['target_id'];
    $members=$target->prepare('SELECT user_id FROM ce_forum_conversation_participants WHERE conversation_id=? ORDER BY user_id');$members->execute([$conv]);
    $recipientIds=$members->fetchAll(PDO::FETCH_COLUMN);
    sort($recipientIds,SORT_STRING);$expectedRecipients=[$alice,$bob];sort($expectedRecipients,SORT_STRING);
    $check(count($recipientIds)===2 && $recipientIds===$expectedRecipients,'only mapped active XenForo participants are recipients');
    $msg=$target->prepare('SELECT body,body_format,created_at FROM ce_forum_conversation_messages WHERE id=?');$msg->execute([$message]);$row=$msg->fetch(PDO::FETCH_ASSOC);
    $check($row['body_format']==='bbcode'&&str_contains($row['body'],'/forum/conversations/'.$conv)&&str_contains($row['body'],'/api/v1/forum/attachments/'.$attachment.'/download'),'private messages keep BBCode and mapped restricted URLs');
    $check((int)$row['created_at']===1600000000,'historical message date preserved');
    $revs=$target->prepare('SELECT body,body_format FROM ce_forum_conversation_revisions WHERE message_id=? AND revision_number=1');$revs->execute([$message]);
    $check($revs->fetch(PDO::FETCH_ASSOC)===['body'=>$row['body'],'body_format'=>'bbcode'],'private baseline revision updated in same transaction as message');
    $filesRow=$target->prepare('SELECT file_data,message_id,post_id,sha256 FROM ce_forum_attachments WHERE id=?');$filesRow->execute([$attachment]);$file=$filesRow->fetch(PDO::FETCH_ASSOC);
    $check($file['message_id']===$message&&$file['post_id']===null&&base64_decode($file['file_data'],true)==='hello world!'&&hash('sha256','hello world!')===$file['sha256'],'private attachment bytes hashed and linked only to message, never public post');
    $tx=new ForumTransaction($db,new AuditLogRepository($db));
    try{$tx->attachments()->get(new ForumAccessPolicy('',[]),$attachment,true);throw new LogicException('Guest fetched private attachment');}
    catch(ForumException $e){$check(in_array($e->status,[401,403,404],true),'guest is denied access to migrated private attachment');}
    $engine->step(20);
    $check((int)$target->query('SELECT COUNT(*) FROM ce_forum_conversation_messages')->fetchColumn()===1,'repeated completed import is idempotent');
    $source->rollBack();unset($engine,$adapter,$source);
    $mutator=$open();$mutator->exec('ALTER TABLE xf_conversation_recipient ADD COLUMN unexpected_plugin_field INT');unset($mutator);
    $source=$open();$adapter=new MultiForumSource($source,'xf_','xenforo','2.3.0','private-v5','https://old.example');
    $engine=new ForumMigrationEngine($db,$adapter,$files);
    $check(!$engine->matchesRecordedPlan($frozen),'changed XenForo private source schema blocks checkpoint reuse');
    echo "XenForo 1.1.5 real private media integration: $n PASS\n";
}finally{
    unset($engine,$adapter,$source,$target,$db,$src);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){
        $f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());
    }
    rmdir($tmp);
}
