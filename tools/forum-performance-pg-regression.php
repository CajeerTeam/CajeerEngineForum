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
(new MigrationRunner($p,$root.'/migrations'))->run('pgsql');
function verify(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$board='22222222-2222-4222-8222-222222222222';
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

$diagnostics=(new \CajeerEngine\Forum\Service\ForumPerformanceCheck($db))->check(true);verify($diagnostics['ok']&&$diagnostics['analyze']&&count($diagnostics['indexes'])===5,'read-only performance diagnostics');

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
$notices=new \CajeerEngine\Forum\Repository\NotificationRepository($db);$notices2=new \CajeerEngine\Forum\Repository\NotificationRepository($db2);
$db->transaction(fn()=>$notices->enqueue('mention',$actor,$post,[...$users,$users[2],'']));
$pending=$notices->pending(1000);verify(count($pending)===203&&count(array_unique(array_column($pending,'event_id')))===1,'batched notification fan-out excludes actor, preference, duplicates');
$k=$pending[0];$k2=$pending[1];$p->beginTransaction();$p2->beginTransaction();$p2->exec("SET LOCAL lock_timeout='100ms'");
verify($notices->claim($k['event_id'],$k['recipient_id'],$k['kind'])!==null,'first worker claims notice');
verify($notices2->claim($k['event_id'],$k['recipient_id'],$k['kind'])===null,'second worker skips locked notice');
verify($notices2->claim($k2['event_id'],$k2['recipient_id'],$k2['kind'])!==null,'second worker claims a different notice');$p2->rollBack();$p->rollBack();
verify(count($notices->pending(1000))===203,'rolled-back claims retain durable pending notices');
$p->exec("INSERT INTO ce_forum_spam_events(id,subject,action,created_at,amount) SELECT md5('reliability-spam-'||n)::uuid,'reliability','reply',CASE WHEN n<=1100 THEN 1 ELSE 100 END,1 FROM generate_series(1,1150) n");
$spam=new \CajeerEngine\Forum\Repository\AntiSpamRepository($db);verify($db->transaction(fn()=>$spam->cleanup(10))===1000,'one bounded PostgreSQL cleanup batch');verify($db->transaction(fn()=>$spam->cleanup(10))===100,'second cleanup batch drains remainder');verify((int)$p->query("SELECT COUNT(*) FROM ce_forum_spam_events WHERE subject='reliability'")->fetchColumn()===50,'fresh spam events retained');
// End a real backend session, do not replay failed transaction, reconnect next tick.
$victim=new DatabaseManager($cfg);$victimPdo=$victim->connection();$pid=(int)$victimPdo->query('SELECT pg_backend_pid()')->fetchColumn();
$reader->query('SELECT pg_terminate_backend('.$pid.')')->fetchColumn();usleep(100000);
try{$victim->transaction(fn(PDO $c)=>$c->query('SELECT 1')->fetchColumn());throw new LogicException('expected disconnected backend');}catch(\CajeerEngine\Database\DatabaseUnavailable $e){verify(true,'native PostgreSQL connection loss mapped to unavailable');}
verify((int)$victim->connection()->query('SELECT 1')->fetchColumn()===1,'next independent database operation reconnects');
