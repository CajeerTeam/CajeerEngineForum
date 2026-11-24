<?php
declare(strict_types=1);
use CajeerEngine\Console\NativeCli;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Queue\{FileQueueDriver,QueueWorker};
use CajeerEngine\Scheduler\SchedulerRunner;
use CajeerEngine\Forum\Service\ForumRuntimeJobs;
use CajeerEngine\Search\{SearchIndexer,FileSearchIndex};
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Integration\{WebhookRepository,WebhookDispatcher};
use CajeerEngine\Security\SignedPayload;
require dirname(__DIR__).'/core/Console/NativeCli.php';
function verify(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('PDO SQLite is required for SQL-backed acceptance');
$root=sys_get_temp_dir().'/forum-unified-'.bin2hex(random_bytes(6));mkdir($root.'/config',0700,true);
try {
    copy(dirname(__DIR__).'/config/database.php',$root.'/config/database.php');
    putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$root.'/test.sqlite');putenv('APP_ENV=testing');putenv('CONTENT_STORAGE=file');putenv('CMS_STORAGE=file');putenv('SEARCH_DRIVER=file');
    $db=new DatabaseManager(new ConfigRepository($root));$pdo=$db->connection();
    $pdo->exec('CREATE TABLE ce_forum_spam_events(id TEXT PRIMARY KEY,created_at INTEGER NOT NULL)');
    $s=$pdo->prepare('INSERT INTO ce_forum_spam_events VALUES (?,?)');$s->execute(['expired',time()-100000]);$s->execute(['current',time()]);
    $q=new FileQueueDriver($root,30);$jobs=new ForumRuntimeJobs($db,$q);
    $search=new SearchIndexer(new ContentEntryRepository($root),new FileSearchIndex($root));$wh=new WebhookRepository($root);
    $runner=new SchedulerRunner($root,$search,$wh,$q,forumJobs:$jobs);
    $run=$runner->run();verify($run['tasks']['forum.jobs.dispatch']['ok']??false,'scheduler publishes real forum SQL work');
    $ids=$run['tasks']['forum.jobs.dispatch']['jobs'];$id=$ids['forum.spam.cleanup']??null;
    verify(is_string($id)&&$jobs->schedule(time()+180)['jobs']['forum.spam.cleanup']===$id,'missed UTC slots reuse outstanding wakeup');
    verify((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_spam_events')->fetchColumn()===2,'scheduler does not execute handler inline');
    $worker=new QueueWorker($q,$search,$wh,new WebhookDispatcher(new SignedPayload(),$wh),database:$db);
    $result=$worker->work('default',1);
    verify($result['ok']&&$result['processed']===1&&$result['forum_spam_cleaned']===1,'worker reserves and executes real SQL cleanup');
    verify($pdo->query('SELECT id FROM ce_forum_spam_events')->fetchAll(PDO::FETCH_COLUMN)===['current'],'expired SQL records removed; current retained');
    verify(($q->diagnostics()['queues']['default']['processed']??0)===1,'completed forum job persisted');
    $next=$jobs->schedule(time()+240)['jobs']['forum.spam.cleanup'];verify($next!==$id,'next due slot can create fresh work after completion');
    echo "Unified forum queue acceptance complete\n";
}finally{
    unset($worker,$runner,$pdo,$db);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);
}
