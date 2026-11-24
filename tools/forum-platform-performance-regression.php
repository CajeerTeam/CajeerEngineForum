<?php
declare(strict_types=1);
use CajeerEngine\Observability\Metrics;
use CajeerEngine\Forum\Service\ForumRuntimeCache;
require dirname(__DIR__).'/core/Console/NativeCli.php';
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$tmp=sys_get_temp_dir().'/forum-perf-'.bin2hex(random_bytes(8));mkdir($tmp.'/storage',0700,true);
try {
    $metrics=new Metrics($tmp);
    for($i=0;$i<1024;$i++)$metrics->buffered('database','query','success',0.001);
    check(glob($tmp.'/storage/logs/observability/metrics-*.json')===[],'1024 SQL samples cause no synchronous file writes');
    $metrics->observe('http','health','2xx',0.01);
    $rows=$metrics->snapshot();
    check($rows['database|query|success']['count']===1024,'operation boundary flush retains all SQL samples');
    check($rows['database|query|success']['buckets'][0]===1024,'batched histogram retains all bucket observations');
    check($rows['http|health|2xx']['count']===1,'boundary observation is persisted');
    putenv('FORUM_CACHE_TTL_SECONDS=60');$ttls=[];
    for($i=0;$i<200;$i++)$ttls[]=ForumRuntimeCache::ttl();
    check(min($ttls)>=51&&max($ttls)<=60,'jitter stays within configured TTL');
    check(count(array_unique($ttls))>1,'expiration is spread over multiple seconds');
    putenv('FORUM_CACHE_TTL_SECONDS=1');check(ForumRuntimeCache::ttl()===5,'minimum TTL preserved');
    putenv('FORUM_CACHE_TTL_SECONDS=1000');$ttl=ForumRuntimeCache::ttl();check($ttl>=255&&$ttl<=300,'maximum TTL preserved');
}finally{
    putenv('FORUM_CACHE_TTL_SECONDS');
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($tmp);
}
