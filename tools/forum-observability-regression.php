<?php
declare(strict_types=1);
use CajeerEngine\Observability\{Metrics,ForumMetrics};
require dirname(__DIR__).'/core/Console/NativeCli.php';
function check(bool $condition,string $description): void {if(!$condition)throw new RuntimeException($description);echo "PASS $description\n";}
$root=sys_get_temp_dir().'/ce-forum-metrics-'.bin2hex(random_bytes(8));mkdir($root.'/storage',0700,true);
try {
    $m=new Metrics($root);
    foreach(['forum.home','forum.page','public.home'] as $route)$m->observe('http',$route,'2xx',0.03);
    $m->observe('http','api.v1.forum.search','2xx',0.7);$m->observe('http','api.v1.forum.search','5xx',0.1);
    $m->observe('http','api.v1.forum.reply','2xx',0.01);$m->observe('http','api.v1.forum.createThread','4xx',0.02);
    $m->observe('http','/forum/private/123','2xx',1.0);
    $summary=$m->forumSummary();check($summary['ssr']['requests']===3&&$summary['ssr']['p95_bucket_ms']===50.0,'SSR grouped histograms');
    check($summary['search']['requests']===2&&$summary['search']['errors_5xx']===1&&$summary['search']['p99_bucket_ms']===1000,'search counts and buckets');
    check($summary['publication']['requests']===2,'publication routes grouped');
    $text=$m->prometheus();check(str_contains($text,'cajeer_forum_request_duration_seconds_count{operation="ssr",outcome="2xx"} 3')&&!str_contains($text,'/forum/private/123'),'fixed labels and cumulative buckets');
    if(in_array('--database',$argv,true)){
        $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA foreign_keys=ON');
        foreach(glob(dirname(__DIR__).'/migrations/sqlite/*.sql') as $f)$pdo->exec(file_get_contents($f));
        $now=time();$insert=$pdo->prepare("INSERT INTO ce_forum_extension_events(id,event_name,payload,created_at,due_at,state,targets,progress) VALUES(?,?,?,?,?,?,?,?)");
        $insert->execute([str_repeat('a',32),'forum.thread.create','{"private":"secret"}',$now-1000,$now-600,'pending','[]','{}']);
        $insert->execute([str_repeat('b',32),'forum.thread.create','{}',$now,$now+600,'pending','[]','{}']);
        $insert->execute([str_repeat('c',32),'forum.thread.create','{}',$now,$now,'dead','[]','{}']);
        $sample=ForumMetrics::collect($pdo,'sqlite',$now);
        check($sample['queues']['extensions']['count']===2&&$sample['queues']['extensions']['oldest_age_seconds']===600,'native SQLite backlog and due age');
        check($sample['queues']['extension_dead']['count']===1,'native SQLite dead-letter count');
        $pdo->exec("UPDATE ce_forum_extension_events SET due_at=".($now+600)." WHERE state='pending'");
        check(ForumMetrics::collect($pdo,'sqlite',$now)['queues']['extensions']['oldest_age_seconds']===0,'scheduled future tasks not overdue');
        $pdo->beginTransaction();for($i=0;$i<10010;$i++)$insert->execute([sprintf('%032x',$i),'forum.thread.create','{}',$now,$now,'pending','[]','{}']);$pdo->commit();
        $sample=ForumMetrics::collect($pdo,'sqlite',$now);check($sample['queues']['extensions']['count']===10001&&$sample['queues']['extensions']['capped'],'count saturation on 10010 rows');
        $export=ForumMetrics::prometheus(['forum_database'=>$sample]);check(str_contains($export,'cajeer_forum_queue_count_capped{queue="extensions"} 1')&&!str_contains($export,'secret'),'native SQL exporter no payload');
    }
    $export=ForumMetrics::prometheus(['forum_database'=>['ok'=>true,'sampled_at'=>123,'queues'=>['extensions'=>['count'=>2,'capped'=>false,'oldest_age_seconds'=>600],'secret-id'=>['count'=>999]]], 'forum_cache'=>['ok'=>true,'enabled'=>true,'stats'=>['hit'=>5,'miss'=>3,'coalesced'=>2,'secret-id'=>1],'used_memory_bytes'=>120,'maxmemory_bytes'=>200]]);
    check(str_contains($export,'cajeer_forum_queue_items{queue="extensions"} 2')&&str_contains($export,'cajeer_forum_cache_requests_total{result="hit"} 5')&&!str_contains($export,'secret-id'),'gauge and cache export only fixed labels');
    check(ForumMetrics::prometheus(['forum_database'=>['ok'=>false]])==='','failed SQL sample never reports healthy zero backlog');
} finally {foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $e){$e->isDir()?rmdir($e->getPathname()):unlink($e->getPathname());}rmdir($root);}
