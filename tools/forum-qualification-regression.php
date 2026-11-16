<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Console/NativeCli.php';
use CajeerEngine\Observability\{Metrics,OperationsMonitor};
use CajeerEngine\Security\RateLimiter;
function check(bool $ok,string $what):void{if(!$ok)throw new RuntimeException($what);echo "PASS $what\n";}
$root=sys_get_temp_dir().'/ce-qualification-'.bin2hex(random_bytes(8));mkdir($root.'/storage/app',0700,true);mkdir($root.'/storage/logs',0700,true);mkdir($root.'/storage/cache',0700,true);
try{
    $m=new Metrics($root);$m->observe('http','forum.home','2xx',.02);
    $path=$root.'/storage/logs/observability/metrics-'.hexdec(substr(hash('sha256','http|forum.home|2xx'),0,1)).'.json.lock';
    $lock=fopen($path,'c+');check(flock($lock,LOCK_EX|LOCK_NB),'hold actual telemetry shard lock');
    $start=hrtime(true);$m->observe('http','forum.home','2xx',.03);$ms=(hrtime(true)-$start)/1e6;
    check($ms<600,'telemetry contention bounded to <600ms');flock($lock,LOCK_UN);fclose($lock);
    $m->observe('http','forum.home','2xx',.04);check($m->forumSummary()['ssr']['requests']===2,'successful telemetry resumes without corrupting counters');
    putenv('RATE_LIMIT_DRIVER=redis');putenv('REDIS_HOST=127.0.0.1');putenv('REDIS_PORT=1');putenv('REDIS_TIMEOUT_SECONDS=0.05');
    $hit=(new RateLimiter($root))->hit('global','test',100,60);check(!$hit['allowed']&&($hit['unavailable']??false),'Redis outage distinguished from exhausted quota and fails closed');
    $ready=(new OperationsMonitor($root))->readiness();check(!$ready['checks']['redis_required']&&!$ready['ok'],'mandatory Redis dependency blocks readiness');
    putenv('RATE_LIMIT_DRIVER=file');putenv('QUEUE_DRIVER=file');$hit=(new RateLimiter($root))->hit('global','test',1,60);check($hit['allowed'],'file limiter normal');$hit=(new RateLimiter($root))->hit('global','test',1,60);check(!$hit['allowed']&&!($hit['unavailable']??false),'quota exhaustion remains distinct');
}finally{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $e){$e->isDir()?rmdir($e->getPathname()):unlink($e->getPathname());}rmdir($root);}
