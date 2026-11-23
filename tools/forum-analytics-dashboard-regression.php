<?php
/** First-party ACP dashboard service: actual SQL under PDO SQLite in CI,
 * strict UTC validation even where SQLite PDO is not installed locally. */
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $name)use($root):void{
    if(str_starts_with($name,'CajeerEngine\\')){
        $p=$root.'/core/'.str_replace('\\','/',substr($name,strlen('CajeerEngine\\'))).'.php';
        if(is_file($p))require_once $p;
    }
});
use CajeerEngine\Forum\Service\ForumAnalyticsDashboard;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

$checks=0;
$ok=static function(bool $test,string $label)use(&$checks):void{
    if(!$test)throw new RuntimeException('FAIL '.$label);$checks++;
};
// Invalid dates must fail BEFORE reaching a database connection.
$service=(new ReflectionClass(ForumAnalyticsDashboard::class))->newInstanceWithoutConstructor();
$time=strtotime('2026-11-23 12:00:00 UTC');
foreach([
    ['2026-02-29','2026-11-23'],['2026-11-24','2026-11-24'],
    ['2026-11-24','2026-11-23'],['2026-11-22','2026-11-24'],
    ['2026-11-22',null],['../2026-11-22','2026-11-23'],
    ['2025-11-01','2026-11-23'],
] as [$from,$until]){
    try{$service->get($from,$until,$time);$failed=false;}
    catch(\CajeerEngine\Forum\ForumException $e){$failed=$e->status===422;}
    $ok($failed,'reject unapproved analytics range '.($from??'null'));
}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    echo "Dashboard: {$checks} validation PASS; SQL integration SKIP (pdo_sqlite unavailable locally)\n";exit(0);
}
$config=new ConfigRepository($root);$config->set('database.default','sqlite');
$db=new DatabaseManager($config);
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
(new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);
$pdo->exec('CREATE TABLE ce_forum_analytics_events(id TEXT PRIMARY KEY,event_name TEXT NOT NULL,created_at INTEGER NOT NULL,aggregated_at INTEGER)');
$pdo->exec('CREATE TABLE ce_forum_analytics_daily(day INTEGER NOT NULL,event_name TEXT NOT NULL,total INTEGER NOT NULL,updated_at INTEGER NOT NULL,PRIMARY KEY(day,event_name))');
$pdo->exec('CREATE TABLE ce_forum_analytics_state(id INTEGER PRIMARY KEY,lock_version INTEGER NOT NULL DEFAULT 0,last_aggregated_at INTEGER,last_recalculated_at INTEGER)');
$pdo->exec('INSERT INTO ce_forum_analytics_state(id,last_aggregated_at,last_recalculated_at) VALUES(1,1234,0)');
$day=static fn(string $s):int=>strtotime($s.' 00:00:00 UTC');
$daily=[['2026-11-20','forum_thread_view',2],['2026-11-22','forum_thread_view',4],['2026-11-22','forum_thread_created',3],['2026-11-23','forum_post_published',2]];
$s=$pdo->prepare('INSERT INTO ce_forum_analytics_daily(day,event_name,total,updated_at) VALUES(?,?,?,1234)');
foreach($daily as [$date,$name,$n])$s->execute([$day($date),$name,$n]);
$s=$pdo->prepare('INSERT INTO ce_forum_analytics_events(id,event_name,created_at,aggregated_at) VALUES(?,?,?,NULL)');
$s->execute([str_repeat('a',32),'forum_thread_created',$day('2026-11-21')+20]);
$s->execute([str_repeat('b',32),'forum_thread_view',$day('2026-11-22')+20]);
$s->execute([str_repeat('c',32),'forum_thread_created',$day('2026-11-23')+20]);
$s->execute([str_repeat('d',32),'forum_thread_created',$day('2026-11-23')+21]);
$service=new ForumAnalyticsDashboard($db);
$r=$service->get('2026-11-22','2026-11-23',$time);
$ok($r['days']===2,'inclusive dates');
$ok($r['summary']['page_views']===5,'applied and pending page views');
$ok($r['summary']['actions']===7,'applied and pending actions');
$ok($r['summary']['total']===12,'total counts');
$ok($r['summary']['previous_total']===3,'prior window complete');
$ok($r['summary']['total_change_pct']===300.0,'comparison percentage');
$ok($r['comparison']['from']==='2026-11-20'&&$r['comparison']['until']==='2026-11-21','prior UTC dates');
$ok($r['daily'][0]['total']===8&&$r['daily'][1]['total']===4,'daily series');
$byEvent=array_column($r['breakdown'],'total','event_name');
$ok(($byEvent['forum_thread_view']??-1)===5&&($byEvent['forum_thread_created']??-1)===5,'sorted event breakdown');
$ok($r['pending_events']===4,'backlog visible');
$ok(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),str_repeat('a',32)),'no event IDs leaked');
$big=$service->get('2025-11-24','2026-11-23',$time);
$ok($big['days']===365&&!$big['comparison']['available'],'365-day comparison unavailable');
$ok(count($big['daily'])===365,'zero-filled date range');
$ok($big['summary']['previous_total']===null,'no fabricated prior counts');
$empty=$service->get('2026-11-19','2026-11-19',$time);
$ok($empty['summary']['total']===0&&$empty['summary']['total_change_pct']===null,'empty day no false growth');
// An aggregation commit changes the ownership of an event, not the result.
$pdo->exec("UPDATE ce_forum_analytics_events SET aggregated_at=2000 WHERE id='".str_repeat('b',32)."'");
$pdo->exec("UPDATE ce_forum_analytics_daily SET total=total+1 WHERE day=".$day('2026-11-22')." AND event_name='forum_thread_view'");
$after=$service->get('2026-11-22','2026-11-23',$time);
$ok($after['summary']['total']===$r['summary']['total'],'rollup transition stable');
echo "Forum analytics dashboard: {$checks} PASS\n";
