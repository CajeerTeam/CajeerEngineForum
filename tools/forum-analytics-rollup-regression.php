<?php

declare(strict_types=1);
/** End-to-end rollup and repair on a real PDO SQLite database. CI also runs
 * the migration suite on all supported drivers. No production data is used. */
$root=dirname(__DIR__);
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    fwrite(STDERR,"SKIP: PDO SQLite extension is unavailable locally (required by CI).\n");exit(77);
}
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $path=$root.'/core/'.str_replace('\\','/',substr($class,strlen('CajeerEngine\\'))).'.php';
        if(is_file($path))require_once $path;
    }
});
$config=new \CajeerEngine\Runtime\ConfigRepository($root);$config->set('database.default','sqlite');
$db=new \CajeerEngine\Database\DatabaseManager($config);
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
(new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);
$pdo->exec('CREATE TABLE ce_forum_community_settings(id INTEGER PRIMARY KEY,revision INTEGER NOT NULL,payload TEXT NOT NULL)');
$pdo->exec(file_get_contents($root.'/migrations/sqlite/0081_forum_analytics_events.sql'));
$pdo->exec(file_get_contents($root.'/migrations/sqlite/0082_forum_analytics_rollup.sql'));
$pdo->exec("INSERT INTO ce_forum_community_settings VALUES (1,1,'{\"analytics_enabled\":true}')");
$events=new \CajeerEngine\Forum\Service\ForumAnalyticsEvents($db);
$rollup=new \CajeerEngine\Forum\Service\ForumAnalyticsRollup($db);
$checks=0;
$check=static function(bool $value,string $reason)use(&$checks):void{if(!$value)throw new RuntimeException($reason);++$checks;};
$now=time();$today=intdiv($now,86400)*86400;
$db->transaction(static function()use($events):void{$events->recordAudit('forum.thread.create',[]);$events->recordAudit('forum.post.reply',[]);});
$check($events->recordPage('forum_thread_view',bin2hex(random_bytes(16))),'page event committed');
$events->recordRead('forum_search_performed');
$before=$events->report(7);
$check($before['page_views']===1&&$before['actions']===3&&$before['pending_events']===4,'pending report counts every event');
$check($rollup->aggregateBatch(2,$now)===2,'first bounded batch');
$check($rollup->aggregateBatch(2,$now)===2,'second bounded batch');
$check($rollup->aggregateBatch(2,$now)===0,'retry does not duplicate counters');
$after=$events->report(7);
$check($before['totals']===$after['totals']&&$after['pending_events']===0,'rollup exact totals');
$check($after['last_aggregated_at']===$now,'aggregation timestamp persisted');
$day=gmdate('Y-m-d',$now);
$check($rollup->recalculate($day,$day,$now)['events']===4,'rebuild from processed raw');
$check($events->report(7)['totals']===$before['totals'],'rebuild idempotent');
$events->recordRead('forum_attachment_downloaded');
$check($events->report(7)['actions']===4,'live pending event visible immediately');
$check($rollup->recalculate($day,$day,$now)['events']===4,'rebuild excludes live unapplied events');
$check($events->report(7)['actions']===4,'live pending event not lost during rebuild');
$check($rollup->aggregateBatch(10,$now)===1,'new event aggregated once');
$check($events->report(7)['actions']===4,'new event remains counted once');
// SQL error during the marker update must roll back the daily increment.
$events->recordRead('forum_search_performed');
$pdo->exec("CREATE TRIGGER rollup_abort BEFORE UPDATE OF aggregated_at ON ce_forum_analytics_events BEGIN SELECT RAISE(ABORT,'simulated crash'); END");
try{$rollup->aggregateBatch(10,$now);throw new RuntimeException('Expected transactional failure');}catch(PDOException){}
$check($events->report(7)['actions']===5,'failed commit left pending data visible');
$pdo->exec('DROP TRIGGER rollup_abort');
$check($rollup->aggregateBatch(10,$now)===1,'safe retry applies exactly once');
$check($events->report(7)['actions']===5,'failed job did not double-count');
try{$rollup->recalculate('1999-01-01','1999-01-01',$now);throw new RuntimeException('Expected old period rejection');}catch(\CajeerEngine\Forum\ForumException $e){$check($e->status===422,'expired raw rebuild forbidden');}
try{$rollup->recalculate($day,'2099-01-01',$now);throw new RuntimeException('Expected future rejection');}catch(\CajeerEngine\Forum\ForumException){$checks++;}
try{$rollup->recalculate('2026-02-30',$day,$now);throw new RuntimeException('Expected invalid date rejection');}catch(\CajeerEngine\Forum\ForumException){$checks++;}
// Old applied raw entries are purged; daily history is retained.
$old=$today-100*86400;
$pdo->prepare('INSERT INTO ce_forum_analytics_events(id,event_name,created_at) VALUES (?,?,?)')->execute([bin2hex(random_bytes(16)),'forum_board_view',$old]);
$check($rollup->aggregateBatch(10,$now)===1,'old unapplied event archived first');
$check($rollup->cleanup($now)['raw_deleted']===1,'only applied old ledger deleted');
$check(($rollup->report(365,$now)['totals']['forum_board_view']??0)===1,'100-day-old aggregate retained');
$check($rollup->cleanup($now)['raw_deleted']===0,'cleanup idempotent');
// Cutoff passes; unprocessed historic rows must not be erased.
$pdo->prepare('INSERT INTO ce_forum_analytics_events(id,event_name,created_at) VALUES (?,?,?)')->execute([bin2hex(random_bytes(16)),'forum_board_view',$old]);
$check($rollup->cleanup($now)['raw_deleted']===0,'backlogged records not lost to retention');
$check($rollup->aggregateBatch(10,$now)===1,'backlogged event eventually applied');
$check($rollup->report(365,$now)['totals']['forum_board_view']===2,'history includes safely processed backlog');
echo "PASS {$checks} PHP SQLite aggregate/rebuild/retention assertions\n";
