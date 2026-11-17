<?php
/** Run only against the dedicated, empty forum_perf PostgreSQL database. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Forum\Repository\ThreadRepository;
use CajeerEngine\Forum\Validation\ForumFeedCursor;
use CajeerEngine\Support\Uuid;
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
if(getenv('FORUM_TEST_DRIVER')!=='pgsql')throw new RuntimeException('PostgreSQL test only');
putenv('DB_DRIVER=pgsql');putenv('DB_DATABASE=forum_perf');
$db=new DatabaseManager(new ConfigRepository($root));$p=$db->connection();
if($p->query('SELECT current_database()')->fetchColumn()!=='forum_perf'||$db->tableExists('ce_users'))throw new RuntimeException('Expected empty disposable forum_perf');
function verify(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$board='22222222-2222-4222-8222-222222222222';
// Reproduce a real MigrationRunner upgrade against populated 0.9.2 data.
// Copy historical migrations unchanged to a throwaway path so no production
// migration or applied checksum is modified to simulate the old version.
$baselineRoot=sys_get_temp_dir().'/forum-pg-pre049-'.bin2hex(random_bytes(8));
if(!mkdir($baselineRoot.'/pgsql',0700,true))throw new RuntimeException('Cannot prepare temporary migration baseline');
try {
    foreach(glob($root.'/migrations/pgsql/*.sql') as $file){
        if(basename($file)>='0049_forum_database_optimization.sql')continue;
        if(!copy($file,$baselineRoot.'/pgsql/'.basename($file)))throw new RuntimeException('Cannot copy baseline migration');
    }
    $baseline=(new MigrationRunner($p,$baselineRoot))->run('pgsql');
    verify($baseline['applied']>=48,'physical baseline through 0048 installed');
} finally {
    foreach(glob($baselineRoot.'/pgsql/*.sql')?:[] as $file)unlink($file);
    rmdir($baselineRoot.'/pgsql');rmdir($baselineRoot);
}
// 25k topics x 4 posts = 100k existing publications before 0049.
$p->exec("INSERT INTO ce_forum_threads(id,board_id,title,updated_at,read_sequence)
 SELECT md5('upgrade-093-thread-'||n)::uuid,'$board','Upgrade topic '||n,
 TIMESTAMPTZ '2024-01-01 00:00:00+00'+n*interval '1 second',4
 FROM generate_series(1,25000) n");
$p->exec("INSERT INTO ce_forum_posts(id,thread_id,body,ordinal,read_sequence)
 SELECT md5('upgrade-093-post-'||n||'-'||ordinal)::uuid,md5('upgrade-093-thread-'||n)::uuid,
 CASE WHEN mod(n,97)=0 THEN 'Unique latencyneedle search term '||n ELSE 'Forum performance benchmark message '||n END,
 ordinal,ordinal FROM generate_series(1,25000) n CROSS JOIN generate_series(1,4) ordinal");
$before=$p->query("SELECT (SELECT COUNT(*) FROM ce_forum_threads) AS threads,
 (SELECT COUNT(*) FROM ce_forum_posts) AS posts,
 (SELECT SUM(read_sequence) FROM ce_forum_posts) AS total_sequence")->fetch(PDO::FETCH_ASSOC);
verify((int)$before['threads']===25000&&(int)$before['posts']===100000,'large pre-upgrade dataset actually populated');
$upgrade=(new MigrationRunner($p,$root.'/migrations'))->run('pgsql');
verify($upgrade['applied']===1&&($upgrade['migrations'][0]['migration']??'')==='0049_forum_database_optimization.sql','only 0049 executed against existing 100k posts');
$after=$p->query("SELECT (SELECT COUNT(*) FROM ce_forum_threads) AS threads,
 (SELECT COUNT(*) FROM ce_forum_posts) AS posts,
 (SELECT SUM(read_sequence) FROM ce_forum_posts) AS total_sequence")->fetch(PDO::FETCH_ASSOC);
verify($after===$before,'existing topic, post and publication-sequence totals preserved');
$runner=new MigrationRunner($p,$root.'/migrations');
verify($runner->verifyApplied('pgsql')['ok']&&$runner->run('pgsql')['applied']===0,'actual migration ledger verified and second run idempotent');
$p->exec('ANALYZE ce_forum_threads');$p->exec('ANALYZE ce_forum_posts');
$evidence=$root.'/storage/tmp/forum-ci';if(!is_dir($evidence))mkdir($evidence,0770,true);
// Save real PostgreSQL 17/18 execution plans and buffer activity, not predicted
// query costs. The pre-existing GIN index remains the FTS access path.
$queries=[
 'unread-board'=>"SELECT id FROM ce_forum_live_threads WHERE board_id='$board' ORDER BY updated_at DESC,id LIMIT 25",
 'fts-posts'=>"SELECT id FROM ce_forum_posts WHERE deleted_at IS NULL AND (to_tsvector('english'::regconfig,body) || to_tsvector('russian'::regconfig,body)) @@ plainto_tsquery('english'::regconfig,'latencyneedle') LIMIT 25",
 'unread-range'=>"SELECT COUNT(*) FROM ce_forum_posts WHERE thread_id=md5('upgrade-093-thread-1')::uuid AND deleted_at IS NULL AND read_sequence>1"
];
// The real production search SQL, with ACL filtering and result-page projection.
[$searchSql,$searchParams]=\CajeerEngine\Forum\Repository\ForumSearchQuery::build(
    'pgsql','latencyneedle',0,'t.board_id='.$p->quote($board),null,'posts'
);
$plans=[];
foreach($queries as $name=>$sql){
    $raw=$p->query('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql)->fetchColumn();
    $plan=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
    verify(is_array($plan)&&isset($plan[0]['Plan']['Actual Total Time']),'native measured PostgreSQL '.$name.' plan available');
    $plans[$name]=$plan;
}
// A valid plan may choose the existing global latest index when almost all
// rows belong to one board; do not fail because the planner made a better
// cost-based choice. Require the new index to be valid and usable.
$validIndex=$p->query("SELECT COUNT(*) FROM pg_index WHERE indexrelid='ce_forum_unread_board_idx'::regclass AND indisvalid AND indisready")->fetchColumn();
verify((int)$validIndex===1,'new unread-board index is valid and ready');
$realSearch=$p->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$searchSql);
$realSearch->execute($searchParams);
$plans['actual-acl-search']=json_decode((string)$realSearch->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
verify(isset($plans['actual-acl-search'][0]['Plan']['Actual Total Time']),'actual production FTS/ACL SQL executed with measured plan');
file_put_contents($evidence.'/forum-database-093-plans.json',json_encode([
 'baseline_topics'=>$before['threads'],'baseline_posts'=>$before['posts'],'migration'=>$upgrade['migrations'][0]['migration'],
 'postgres_version'=>$p->query('SHOW server_version')->fetchColumn(),'plans'=>$plans
 ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$p->exec("INSERT INTO ce_forum_threads(id,board_id,title,updated_at) SELECT md5('perf-'||n)::uuid,'$board','Performance '||n, TIMESTAMPTZ '2025-01-01 00:00:00+00'+(n/40)*interval '1 second' FROM generate_series(1,12000) n");
$p->exec('ANALYZE ce_forum_threads');
$repo=new ThreadRepository($db);$first=$repo->latestAfter([$board],'',null);$cursor=ForumFeedCursor::decode(ForumFeedCursor::encode($first[24]));$second=$repo->latestAfter([$board],'',$cursor);
verify(count($first)===26&&count($second)===26&&array_column(array_slice($second,0,25),'id')===array_column($repo->latest(25,[$board]),'id'),'cursor matches legacy order including timestamp ties');
verify($repo->latestAfter([],'',$cursor)===[],'current ACL excludes all rows even with valid cursor');
$plan=$p->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT id FROM ce_forum_live_threads WHERE updated_at<? ORDER BY updated_at DESC,id LIMIT 26');$plan->execute([$cursor['at']]);$explain=$plan->fetchColumn();verify(is_string($explain)&&str_contains($explain,'ce_forum_live_latest_idx'),'native planner selects live seek index');
$evidence=$root.'/storage/tmp/forum-ci';if(!is_dir($evidence))mkdir($evidence,0770,true);file_put_contents($evidence.'/forum-seek-plan.json',$explain);
// Two independent writers with different transaction-selected shards. No shared
// generation row lock is permitted between these writes, even before commit.
$a=$db->connection('pgsql');$b=$db->connection('pgsql');$reader=$db->connection('pgsql');
$generation=static fn(PDO $c)=>(string)$c->query("SELECT generation FROM ce_forum_cache_generation_effective WHERE name='ce_forum_threads'")->fetchColumn();
$old=$generation($p);$reader->beginTransaction();$reader->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');verify($generation($reader)===$old,'reader pins old generation');
$a->beginTransaction();$b->beginTransaction();$a->exec("SET LOCAL lock_timeout='2s'");$b->exec("SET LOCAL lock_timeout='2s'");
$sa=(int)$a->query('SELECT mod(txid_current(),16)')->fetchColumn();$sb=(int)$b->query('SELECT mod(txid_current(),16)')->fetchColumn();
if($sa===$sb){$b->rollBack();$b->beginTransaction();$b->exec("SET LOCAL lock_timeout='2s'");$sb=(int)$b->query('SELECT mod(txid_current(),16)')->fetchColumn();}verify($sa!==$sb,'writers use separate shards');
$a->prepare('UPDATE ce_forum_threads SET title=? WHERE id=?')->execute(['Writer A',$first[0]['id']]);
$b->prepare('UPDATE ce_forum_threads SET title=? WHERE id=?')->execute(['Writer B',$first[1]['id']]);
verify($generation($p)===$old,'uncommitted generation invisible');
$b->commit();$middle=$generation($p);verify($middle!==$old,'second writer commits without waiting for first');
$a->commit();$final=$generation($p);verify($final!==$middle&&$final!==$old,'late commit changes digest even after newer transaction commits');
verify($generation($reader)===$old,'old snapshot remains consistent');$reader->commit();verify($generation($reader)===$final,'new snapshot sees both commits');
$a->beginTransaction();$a->prepare('UPDATE ce_forum_threads SET title=? WHERE id=?')->execute(['Rollback',$first[0]['id']]);$a->rollBack();verify($generation($p)===$final,'rollback leaves digest unchanged');
verify((int)$p->query('SELECT COUNT(*) FROM ce_forum_cache_generation_effective')->fetchColumn()===27,'all generation rows preserved');

$diagnostics=(new \CajeerEngine\Forum\Service\ForumPerformanceCheck($db))->check(true);verify($diagnostics['ok']&&$diagnostics['analyze']&&count($diagnostics['indexes'])===7,'read-only performance diagnostics');

// Shared publication locks allow independent writers in one board, but policy edits
// still block. Use separate DatabaseManagers so repository code actually takes locks.
$cfg=new ConfigRepository($root);$db2=new DatabaseManager($cfg);$p2=$db2->connection();
$boards=new \CajeerEngine\Forum\Repository\BoardRepository($db);$boards2=new \CajeerEngine\Forum\Repository\BoardRepository($db2);
$p->beginTransaction();$p2->beginTransaction();$p2->exec("SET LOCAL lock_timeout='1s'");
verify($boards->lockPublicationBoard($board)!==null&&$boards2->lockPublicationBoard($board)!==null,'two independent publications share board lock');
$p2->rollBack();$p2->beginTransaction();$p2->exec("SET LOCAL lock_timeout='100ms'");
try{$boards2->lockBoard($board);throw new LogicException('exclusive board lock must wait');}catch(PDOException $e){verify(($e->errorInfo[0]??$e->getCode())==='55P03','exclusive policy edit blocked while publication is active');}$p2->rollBack();$p->commit();
$p2->beginTransaction();verify($boards2->lockBoard($board)!==null,'policy edit acquires lock after publication commits');$p2->rollBack();

// Real production repository fan-out crosses the chunk boundary. Retain one event
// ID, skip actor, duplicate recipient, and disabled notification preference.
$p->exec("INSERT INTO ce_users(id,email,name,password_hash) SELECT md5('reliability-user-'||n)::uuid,'reliability-'||n||'@example.test','Load member','unused' FROM generate_series(1,205) n");
$users=$p->query("SELECT id FROM ce_users WHERE email LIKE 'reliability-%@example.test' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$actor=$users[0];$skip=$users[1];$post=Uuid::v4();$tid=$first[0]['id'];
$p->prepare('INSERT INTO ce_forum_notification_preferences(user_id,mention) VALUES(?,0)')->execute([$skip]);
$posts=new \CajeerEngine\Forum\Repository\PostRepository($db);
$db->transaction(function()use($repo,$posts,$tid,$post,$actor){$repo->lockThread($tid);$posts->insert($post,$tid,$actor,'reliability marker',1);});
verify((int)$p->query("SELECT read_sequence FROM ce_forum_posts WHERE id=".$p->quote($post))->fetchColumn()===1,'UPDATE RETURNING reserves real post sequence');
// Actual repository path on >30k threads: snapshot is spilled to a private
// temp file, locks are ordered, and updates are issued in bounded batches.
$readRepo=new \CajeerEngine\Forum\Repository\ReadStateRepository($db);
$candidates=$db->readSnapshot(fn()=> $readRepo->candidateThreads([$board]));
verify($candidates instanceof SplTempFileObject && $candidates->getSize()>1024*1024,'mark-all snapshot spills past the 1 MiB memory bound');
$lateTopic=Uuid::v4();
$db->transaction(function()use($repo,$posts,$lateTopic,$board,$actor){
    $repo->insert($lateTopic,$board,$actor,'Topic after captured snapshot');
    $repo->lockThread($lateTopic);
    $posts->insert(Uuid::v4(),$lateTopic,$actor,'Late first post',1);
});
$bulkCount=$db->transaction(function()use($readRepo,$candidates,$actor){
    $locked=$readRepo->lockCandidates($candidates);
    $marked=0;$batch=[];
    while(!$locked->eof()){
        $line=trim($locked->fgets());if($line==='')continue;
        [$id,$board]=explode(' ',$line,2);
        $batch[]=$id;
        if(count($batch)===500){$readRepo->markThreads($actor,$batch);$marked+=count($batch);$batch=[];}
    }
    if($batch){$readRepo->markThreads($actor,$batch);$marked+=count($batch);}
    return $marked;
});
verify($bulkCount>=37000,'large mark-all stream executes actual writes in 500-row batches');
verify((int)$p->query('SELECT COUNT(*) FROM ce_forum_read_state WHERE user_id='.$p->quote($actor))->fetchColumn()===$bulkCount,'one committed read state per captured topic');
verify((int)$p->query('SELECT COUNT(*) FROM ce_forum_read_state WHERE user_id='.$p->quote($actor).' AND thread_id='.$p->quote($lateTopic))->fetchColumn()===0,'topic published after snapshot is not marked');

$notices=new \CajeerEngine\Forum\Repository\NotificationRepository($db);$notices2=new \CajeerEngine\Forum\Repository\NotificationRepository($db2);
$db->transaction(fn()=>$notices->enqueue('mention',$actor,$post,[...$users,$users[2],'']));
$pending=$notices->pending(1000);verify(count($pending)===203&&count(array_unique(array_column($pending,'event_id')))===1,'batched notification fan-out excludes actor, preference, duplicates');
$k=$pending[0];$k2=$pending[1];$p->beginTransaction();$p2->beginTransaction();$p2->exec("SET LOCAL lock_timeout='100ms'");
verify($notices->claim($k['event_id'],$k['recipient_id'],$k['kind'])!==null,'first worker claims notice');
verify($notices2->claim($k['event_id'],$k['recipient_id'],$k['kind'])===null,'second worker skips locked notice');
verify($notices2->claim($k2['event_id'],$k2['recipient_id'],$k2['kind'])!==null,'second worker claims a different notice');$p2->rollBack();$p->rollBack();
verify(count($notices->pending(1000))===203,'rolled-back claims retain durable pending notices');
// Exercise the actual UNION-filtered repository, including the distinct
// parameter sets for SELECT and UPDATE. A neighbor's pending inbox stays hidden.
$db->transaction(fn()=> $notices->finish($k['event_id'],$k['recipient_id'],true,$k['kind']));
$delivered=$notices->inbox($k['recipient_id'],[$board],0);
verify(count($delivered)===1&&$delivered[0]['id']===$k['event_id']&&$notices->countUnread($k['recipient_id'],[$board])===1,'real visible inbox and unread count after delivery');
verify($notices->inbox($k2['recipient_id'],[$board],0)===[],'undelivered foreign notices excluded');
verify($notices->mark($k['recipient_id'],[$board],$k['event_id'])&&$notices->countUnread($k['recipient_id'],[$board])===0,'real inbox mark updates only authorized recipient');
$quoted=$p->quote($k['recipient_id']);
$noticeSql="SELECT event_id FROM ce_forum_notifications WHERE recipient_id=$quoted AND state=1 ORDER BY created_at DESC,event_id DESC LIMIT 25";
$noticePlan=json_decode((string)$p->query('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$noticeSql)->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
verify(isset($noticePlan[0]['Plan']['Actual Total Time']),'measured recipient notification plan');
file_put_contents($evidence.'/forum-inbox-093-plan.json',json_encode(['recipient_count'=>(int)$p->query("SELECT COUNT(*) FROM ce_forum_notifications WHERE state=1")->fetchColumn(),'plan'=>$noticePlan],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$p->exec("INSERT INTO ce_forum_spam_events(id,subject,action,created_at,amount) SELECT md5('reliability-spam-'||n)::uuid,'reliability','reply',CASE WHEN n<=1100 THEN 1 ELSE 100 END,1 FROM generate_series(1,1150) n");
$spam=new \CajeerEngine\Forum\Repository\AntiSpamRepository($db);verify($db->transaction(fn()=>$spam->cleanup(10))===1000,'one bounded PostgreSQL cleanup batch');verify($db->transaction(fn()=>$spam->cleanup(10))===100,'second cleanup batch drains remainder');verify((int)$p->query("SELECT COUNT(*) FROM ce_forum_spam_events WHERE subject='reliability'")->fetchColumn()===50,'fresh spam events retained');
// End a real backend session, do not replay failed transaction, reconnect next tick.
$victim=new DatabaseManager($cfg);$victimPdo=$victim->connection();$pid=(int)$victimPdo->query('SELECT pg_backend_pid()')->fetchColumn();
$reader->query('SELECT pg_terminate_backend('.$pid.')')->fetchColumn();usleep(100000);
try{$victim->transaction(fn(PDO $c)=>$c->query('SELECT 1')->fetchColumn());throw new LogicException('expected disconnected backend');}catch(\CajeerEngine\Database\DatabaseUnavailable $e){verify(true,'native PostgreSQL connection loss mapped to unavailable');}
verify((int)$victim->connection()->query('SELECT 1')->fetchColumn()===1,'next independent database operation reconnects');

// Search time budget is transaction-local. A cancelled SELECT rolls back cleanly
// and neither poisons the next request nor overrides a stricter DBA setting.
$budget=\CajeerEngine\Forum\Service\ForumSearchBudget::class;
$original=$p->query('SHOW statement_timeout')->fetchColumn();putenv('FORUM_SEARCH_TIMEOUT_MS=100');
try{$budget::run($db,fn()=> $p->query('SELECT pg_sleep(0.5)')->fetchColumn());throw new LogicException('expected search timeout');}
catch(\CajeerEngine\Forum\ForumException $e){verify($e->status===503,'PostgreSQL search cancellation is a retryable 503');}
verify(!$p->inTransaction()&&$p->query('SHOW statement_timeout')->fetchColumn()===$original&&(int)$p->query('SELECT 1')->fetchColumn()===1,'search budget rolls back without poisoning connection');
$p->exec("SET statement_timeout='50ms'");
verify($budget::run($db,fn()=> $p->query('SHOW statement_timeout')->fetchColumn())==='50ms','search respects stricter DBA budget');
$p->exec('RESET statement_timeout');putenv('FORUM_SEARCH_TIMEOUT_MS');

$maintenance=new \CajeerEngine\Forum\Service\ForumSearchMaintenance($db);
verify($maintenance->check()['ok'],'native search diagnostics identify valid expression indexes');
$db->transaction(function(PDO $pdo)use($maintenance){
    $pdo->exec('ALTER INDEX ce_forum_fts_threads RENAME TO ce_forum_fts_threads_original');
    $pdo->exec("CREATE INDEX ce_forum_fts_threads ON ce_forum_posts USING gin (to_tsvector('simple'::regconfig,body))");
    verify(!$maintenance->check()['indexes']['thread']['ok'],'diagnostics reject a GIN index on the wrong source table');
    $pdo->exec('DROP INDEX ce_forum_fts_threads');$pdo->exec('ALTER INDEX ce_forum_fts_threads_original RENAME TO ce_forum_fts_threads');
});
