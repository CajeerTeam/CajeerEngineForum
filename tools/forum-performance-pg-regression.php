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
