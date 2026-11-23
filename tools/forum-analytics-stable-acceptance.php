#!/usr/bin/env php
<?php
/**
 * Forum 1.3.0: fail-closed, real-PDO analytics acceptance on disposable SQL.
 * CI must run this on SQLite, PostgreSQL 17/18 and MySQL 8.4/MariaDB 10.11.
 * Never point the PostgreSQL/MySQL mode at a real forum database.
 */
declare(strict_types=1);

use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Service\{ForumAnalyticsEvents,ForumAnalyticsRollup,ForumAnalyticsDashboard,ForumEngagementAnalytics,ForumNativeAnalytics};
use Symfony\Component\HttpFoundation\Request;

$root=dirname(__DIR__);
require_once $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')?:'sqlite';
if (!in_array($driver,['sqlite','pgsql','mysql'],true) || !in_array($driver,PDO::getAvailableDrivers(),true)) {
    throw new RuntimeException('Analytics acceptance requires an installed real PDO driver');
}
if ($driver !== 'sqlite') {
    // Set before ConfigRepository parses config/database.php, never after.
    putenv('DB_DRIVER='.$driver);
    putenv('DB_DATABASE=forum_analytics');
}
$config=new ConfigRepository($root);
$config->set('database.default',$driver);
$db=new DatabaseManager($config);
if ($driver==='sqlite') {
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    (new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);
    $pdo->exec('CREATE TABLE ce_forum_community_settings (id INTEGER PRIMARY KEY,revision INTEGER NOT NULL,payload TEXT NOT NULL)');
    $pdo->exec("INSERT INTO ce_forum_community_settings VALUES (1,1,'{\"analytics_enabled\":true}')");
    foreach (['0081_forum_analytics_events.sql','0082_forum_analytics_rollup.sql'] as $file) {
        $sql=file_get_contents($root.'/migrations/sqlite/'.$file);
        if ($sql===false)throw new RuntimeException('Missing real analytics migration: '.$file);
        $pdo->exec($sql);
    }
    $pdo->exec('CREATE TABLE ce_users (id TEXT PRIMARY KEY,created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE ce_roles (id TEXT PRIMARY KEY,handle TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE ce_user_roles (user_id TEXT NOT NULL,role_id TEXT NOT NULL,PRIMARY KEY(user_id,role_id))');
    $pdo->exec('CREATE TABLE ce_forum_posts (id TEXT PRIMARY KEY,author_id TEXT,created_at TEXT NOT NULL,ordinal INTEGER NOT NULL,deleted_at TEXT)');
    $pdo->exec('CREATE VIEW ce_forum_live_posts AS SELECT * FROM ce_forum_posts WHERE deleted_at IS NULL');
    $pdo->exec(file_get_contents($root.'/migrations/sqlite/0083_forum_engagement_indexes.sql'));
} else {
    $expected='forum_analytics';
    $name=$driver==='pgsql'?'SELECT current_database()':'SELECT DATABASE()';
    $pdo=$db->connection();
    if ($pdo->query($name)->fetchColumn()!==$expected)throw new RuntimeException('Only disposable CI database '.$expected.' is allowed');
    (new MigrationRunner($pdo,$root.'/migrations'))->run($driver);
    if ((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_analytics_events')->fetchColumn()!==0) {
        throw new RuntimeException('Analytics acceptance requires an empty disposable event journal');
    }
    $pdo->prepare('UPDATE ce_forum_community_settings SET payload=?,revision=revision+1 WHERE id=1')
        ->execute(['{"analytics_enabled":true}']);
}
$checks=0;
$check=static function(bool $ok,string $name)use(&$checks):void{
    if(!$ok)throw new RuntimeException('FAIL analytics stable: '.$name);
    $checks++;
    echo "PASS $name\n";
};
$at=time();$today=intdiv($at,86400)*86400;
$events=new ForumAnalyticsEvents($db);
$rollup=new ForumAnalyticsRollup($db);
$dashboard=new ForumAnalyticsDashboard($db);
$check($events->report(7)['pending_events']===0,'clean initial event ledger');
$db->transaction(static function()use($events):void{
    $events->recordAudit('forum.thread.create',[]);
    $events->recordAudit('forum.post.reply',[]);
});
$events->recordRead('forum_search_performed');
$before=$events->report(7);
$check($before['actions']===3 && $before['pending_events']===3,'real transaction/read events visible without worker');
$oldKey=getenv('APP_KEY');
putenv('APP_KEY=forum-analytics-test-only-0123456789abcdef123456789abcdef');
try {
    $receipt=ForumNativeAnalytics::issue('thread',$at);
    $check(is_string($receipt) && ForumNativeAnalytics::verify($receipt,$at)[0]==='forum_thread_view','signed page receipt verified');
    $makeRequest=static fn(string $origin='https://forum.example'):Request=>Request::create(
        'https://forum.example/api/v1/forum/analytics/page-view','POST',[],[],[],[
            'HTTPS'=>'on','HTTP_HOST'=>'forum.example','HTTP_ORIGIN'=>$origin,
            'HTTP_SEC_FETCH_SITE'=>'same-origin','HTTP_SEC_FETCH_MODE'=>'cors',
            'CONTENT_TYPE'=>'application/json',
        ],json_encode(['receipt'=>$receipt],JSON_THROW_ON_ERROR)
    );
    $collector=new ForumNativeAnalytics($db);
    $check($collector->collect($makeRequest(),$receipt),'valid HTTPS first-party page collection');
    $check(!$collector->collect($makeRequest(),$receipt),'replayed receipt rejected by database primary key');
    try {$collector->collect($makeRequest('https://attacker.example'),$receipt);throw new RuntimeException('Cross-site Origin accepted');}
    catch(ForumException $e){$check($e->status===403,'cross-site Origin cannot collect');}
    $check($events->report(7)['page_views']===1,'one signed page view persisted');
} finally {if($oldKey===false)putenv('APP_KEY');else putenv('APP_KEY='.$oldKey);}

$dashboardBefore=$dashboard->get(gmdate('Y-m-d',$at),gmdate('Y-m-d',$at),$at);
$check($dashboardBefore['summary']['total']===4,'ACP live dashboard reflects raw pending events');
$check($rollup->aggregateBatch(2,$at)===2,'first bounded worker batch committed');
$check($rollup->aggregateBatch(10,$at)===2,'second worker batch committed');
$check($rollup->aggregateBatch(10,$at)===0,'empty worker retry idempotent');
$after=$events->report(7);
$check($before['actions']===$after['actions'] && $after['page_views']===1 && $after['pending_events']===0,'rollup produces identical counts without duplicate');
$dashboardAfter=$dashboard->get(gmdate('Y-m-d',$at),gmdate('Y-m-d',$at),$at);
$check($dashboardAfter['summary']===$dashboardBefore['summary'],'ACP report stable across rollup transition');
$day=gmdate('Y-m-d',$at);
$rebuild=$rollup->recalculate($day,$day,$at);
$check($rebuild['events']===4,'real recalculation rebuilds exactly committed ledger');
$check($dashboard->get($day,$day,$at)['summary']['total']===4,'recalculation leaves dashboard unchanged');
$events->recordRead('forum_attachment_downloaded');
$check($rollup->recalculate($day,$day,$at)['events']===4,'rebuild excludes pending raw events');
$check($dashboard->get($day,$day,$at)['summary']['total']===5,'pending events remain visible during rebuild');
$check($rollup->aggregateBatch(10,$at)===1,'pending event processed exactly once');
$check($dashboard->get($day,$day,$at)['summary']['total']===5,'no double count after rebuild and retry');
// Failed mutation must leave both business write and telemetry uncommitted.
try{$db->transaction(static function()use($events):void{
    $events->recordAudit('forum.post.reply',[]);
    throw new RuntimeException('Simulated failed publication');
});throw new RuntimeException('Expected rollback');}
catch(RuntimeException $e){if($e->getMessage()!=='Simulated failed publication')throw $e;}
$check($dashboard->get($day,$day,$at)['summary']['total']===5,'aborted publication adds no analytics event');
$pdo->prepare('UPDATE ce_forum_community_settings SET payload=? WHERE id=1')->execute(['{"analytics_enabled":false}']);
$events->recordRead('forum_search_performed');
$check($events->report(7)['actions']===4,'disabled analytics does not persist optional read');
$pdo->prepare('UPDATE ce_forum_community_settings SET payload=? WHERE id=1')->execute(['{"analytics_enabled":true}']);
$old=$today-100*86400;
$pdo->prepare('INSERT INTO ce_forum_analytics_events(id,event_name,created_at) VALUES (?,?,?)')
    ->execute([bin2hex(random_bytes(16)),'forum_board_view',$old]);
$check($rollup->cleanup($at)['raw_deleted']===0,'unapplied old event survives retention');
$check($rollup->aggregateBatch(10,$at)===1,'old event rolled into 365-day storage');
$check($rollup->cleanup($at)['raw_deleted']===1,'retention deletes only applied raw');
$check(($rollup->report(365,$at)['totals']['forum_board_view']??0)===1,'historical aggregate preserved after raw cleanup');
$check($rollup->cleanup($at)['raw_deleted']===0,'retention retry idempotent');
try{$rollup->recalculate('1999-01-01','1999-01-01',$at);throw new RuntimeException('Out-of-retention rebuild accepted');}
catch(ForumException $e){$check($e->status===422,'rebuild cannot erase historical aggregate');}
$engagement=(new ForumEngagementAnalytics($db))->get(7,$at);
$check(count($engagement['daily'])===7 && $engagement['summary']['contributions']===0,'actual engagement SQL query runs on selected driver');
$serialized=json_encode([$dashboardAfter,$engagement],JSON_THROW_ON_ERROR);
$check(!str_contains($serialized,'author_id')&&!str_contains($serialized,'receiptId'),'ACP analytics report excludes personal identifiers');
echo "Forum 1.3.0 real $driver analytics acceptance: $checks PASS\n";
