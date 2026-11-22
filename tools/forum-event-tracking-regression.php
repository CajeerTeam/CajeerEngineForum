<?php

declare(strict_types=1);
$root=dirname(__DIR__);
if (!in_array('sqlite', \PDO::getAvailableDrivers(),true)) {
    fwrite(STDERR,"FAIL: PDO SQLite is required for Forum Event Tracking regression\n");exit(2);
}
require_once $root.'/core/Forum/Service/ForumAnalyticsEvents.php';
spl_autoload_register(static function(string $class)use($root):void{
    if (str_starts_with($class,'CajeerEngine\\')) {
        $path=$root.'/core/'.str_replace('\\','/',substr($class,strlen('CajeerEngine\\'))).'.php';
        if(is_file($path))require_once $path;
    }
});
$config=new \CajeerEngine\Runtime\ConfigRepository($root);
$config->set('database.default','sqlite');
$db=new \CajeerEngine\Database\DatabaseManager($config);
$pdo=new \PDO('sqlite::memory:',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
$prop=new \ReflectionProperty($db,'pdo');$prop->setValue($db,$pdo);
$pdo->exec('CREATE TABLE ce_forum_community_settings(id INTEGER PRIMARY KEY,revision INTEGER NOT NULL,payload TEXT NOT NULL)');
$pdo->exec(file_get_contents($root.'/migrations/sqlite/0081_forum_analytics_events.sql'));
$pdo->exec("INSERT INTO ce_forum_community_settings(id,revision,payload) VALUES(1,0,'{\"analytics_enabled\":false}')");
$events=new \CajeerEngine\Forum\Service\ForumAnalyticsEvents($db);
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new \RuntimeException($message);$checks++;};
$db->transaction(static fn()=> $events->recordAudit('forum.thread.create',['thread_id'=>'private-id']));
$check($pdo->query('SELECT COUNT(*) FROM ce_forum_analytics_events')->fetchColumn()==0,'Disabled analytics wrote an event');
$pdo->exec("UPDATE ce_forum_community_settings SET payload='{\"analytics_enabled\":true}' WHERE id=1");
try{$events->recordAudit('forum.thread.create',[]);throw new \RuntimeException('Expected transaction guard');}catch(\LogicException) {$checks++;}
$db->transaction(static function()use($events){
    $events->recordAudit('forum.thread.create',['thread_id'=>'forbidden']);
    $events->recordAudit('forum.post.reply',['body'=>'never persist']);
    $events->recordAudit('forum.member.register',['email'=>'private@example.invalid']);
    $events->recordAudit('forum.reaction.set',['change'=>'added','enabled'=>true]);
    $events->recordAudit('forum.reaction.set',['change'=>'removed','enabled'=>false]);
    $events->recordAudit('forum.reaction.set',['change'=>'changed','enabled'=>true]);
    $events->recordAudit('forum.reaction.set',['change'=>'unchanged','enabled'=>true]);
    $events->recordAudit('forum.conversation.send',['message_id'=>'secret']);
});
$check($pdo->query('SELECT COUNT(*) FROM ce_forum_analytics_events')->fetchColumn()==6,'Wrong number of committed events');
$records=$pdo->query('SELECT * FROM ce_forum_analytics_events')->fetchAll(\PDO::FETCH_ASSOC);
$check(!str_contains(json_encode($records,JSON_THROW_ON_ERROR),'private')&&!str_contains(json_encode($records,JSON_THROW_ON_ERROR),'secret'),'Private payload leaked');
$check(array_keys($records[0])===['id','event_name','created_at'],'Unexpected analytics fields');
try{$db->transaction(static function()use($events){$events->recordAudit('forum.post.edit',[]);throw new \RuntimeException('abort');});}catch(\RuntimeException){}
$check($pdo->query('SELECT COUNT(*) FROM ce_forum_analytics_events')->fetchColumn()==6,'Rollback failed');
$events->recordRead('forum_search_performed');$events->recordRead('forum_attachment_downloaded');
$check($pdo->query('SELECT COUNT(*) FROM ce_forum_analytics_events')->fetchColumn()==8,'Successful reads not counted');
try{$events->recordRead('forum_conversation_read');throw new \RuntimeException('Expected unsupported-event exception');}catch(\InvalidArgumentException) {$checks++;}
$check(count($events->counts(time()-30,time()+30))===8,'Counts mismatch');
$pdo->exec('UPDATE ce_forum_analytics_events SET created_at=1');
$check($events->cleanup(time())===8,'Old records cleanup failed');
$check($events->cleanup(time())===0,'Cleanup not idempotent');
// A corrupted metrics sink must not poison the publication transaction.
$pdo->exec('CREATE TABLE ce_audit_log(id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,actor_id TEXT,context TEXT,ip_address TEXT,user_agent TEXT,created_at TEXT)');
$pdo->exec('DROP TABLE ce_forum_analytics_events');
$tx=new \CajeerEngine\Forum\Service\ForumTransaction($db,new \CajeerEngine\Database\Repository\AuditLogRepository($db));
$tx->write(static fn()=> $tx->audit('forum.thread.create',null,['thread_id'=>'private']));
$check($pdo->query('SELECT COUNT(*) FROM ce_audit_log')->fetchColumn()==1,'Telemetry failure rolled back a publication audit');
echo "PASS {$checks} PHP SQL event tracking tests\n";
