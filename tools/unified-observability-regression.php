<?php
declare(strict_types=1);
use CajeerEngine\Observability\{Metrics,Operation,OperationsMonitor,PlatformProbes,TraceContext,TraceScope};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\AI\{OpenAiClient,AiProviderException};
use CajeerEngine\Integration\{WebhookRepository,WebhookDispatcher};
use CajeerEngine\Security\SignedPayload;
use CajeerEngine\Support\JsonFile;
require dirname(__DIR__).'/core/Console/NativeCli.php';
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$source=dirname(__DIR__);$root=sys_get_temp_dir().'/ce-unified-obs-'.bin2hex(random_bytes(8));
mkdir($root.'/storage/app',0700,true);mkdir($root.'/storage/cache');mkdir($root.'/storage/logs');mkdir($root.'/config');
foreach(glob($source.'/config/*.php') as $p)copy($p,$root.'/config/'.basename($p));
copy($source.'/VERSION',$root.'/VERSION');copy($source.'/release.json',$root.'/release.json');
try{
    foreach(['QUEUE_DRIVER=file','SCHEDULER_DRIVER=file','CACHE_DRIVER=file','FORUM_CACHE_ENABLED=false','RATE_LIMIT_DRIVER=file','DB_DRIVER=sqlite','SQLITE_DATABASE='.$root.'/test.sqlite','OBSERVABILITY_OTLP_ENDPOINT=','OPENAI_API_KEY=','STORAGE_DRIVER=local'] as $env)putenv($env);
    $m=new Metrics($root);$cfg=new ConfigRepository($root);$db=new DatabaseManager($cfg);$probe=new PlatformProbes($root,$cfg,$db);
    check($probe->ai()['configured']===false&&$probe->redis()['enabled']===false,'disabled optional dependencies are not reported as failures');
    $secret='sk-proj-'.str_repeat('A',32);
    try{(new OpenAiClient($root))->complete($secret,'test',[['role'=>'user','content'=>$secret]],16);throw new RuntimeException('Expected blocked credential');}catch(AiProviderException $e){check($e->httpStatus===422,'real OpenAI transport rejects sensitive outbound input before I/O');}
    try{Operation::measure('ai','openai.responses',static fn()=>throw new AiProviderException('private detail',429),$root);throw new RuntimeException('Expected rate limit');}catch(AiProviderException $e){check($e->httpStatus===429,'instrumentation preserves provider exception');}
    $value=Operation::measure('analytics','native.rollup',static fn()=>42,$root,static fn()=>throw new RuntimeException('classifier'));check($value===42,'telemetry classifier failure preserves business result');
    $dispatcher=new WebhookDispatcher(new SignedPayload(),new WebhookRepository($root));
    $delivery=$dispatcher->dispatchWebhook(['id'=>'test-private-id','name'=>'private name','secret'=>str_repeat('b',32),'url'=>'http://127.0.0.1/private-secret'],'manual.test',['secret'=>'private-body']);
    check(!$delivery['ok'],'real webhook dispatcher blocks unsafe URL');
    try{(new CajeerEngine\Security\PublicHttpClient(1,$root))->request('https://example.invalid/private-secret','DELETE');throw new RuntimeException('Expected transport rejection');}catch(InvalidArgumentException $e){check(true,'actual outbound HTTP validation instrumented without network');}
    for($i=0;$i<5;$i++)$m->buffered('integration','provider.execute',$i<3?'failure':'success',0.1);
    $m->buffered('redis','cache.get','miss',0.001);$m->buffered('redis','cache.get','hit',0.002);$m->buffered('redis','raw-secret-key','failure',0.02);
    $summary=$m->recentSummary();$c=$summary['components'];
    check($c['ai']['count']===2&&$c['ai']['failures']===2&&$c['ai']['rejected']===2,'AI rejection and throttling are distinct exported outcomes');
    check($c['integration']['count']===7&&$c['integration']['failures']===5,'recent integration metrics include real rejected webhook and provider outcomes');
    check($c['redis']['count']===3&&$c['redis']['failures']===1&&isset($c['redis']['operations']['other']),'Redis cache misses are not failures; unknown operation is bounded');
    check($c['http']['count']===0&&$c['http']['average_ms']===null&&$c['http']['failure_percent']===null,'no observations are not a fabricated healthy latency');
    $serialized=json_encode($summary).$m->prometheus();check(!str_contains($serialized,$secret)&&!str_contains($serialized,'private-body')&&!str_contains($serialized,'private detail')&&!str_contains($serialized,'raw-secret-key')&&!str_contains($serialized,'test-private-id'),'credentials, exception details and IDs absent from new telemetry');
    // Simulate a pre-upgrade cumulative row and a sample older than the recent window.
    $key='database|query|success';$shard=hexdec(substr(hash('sha256',$key),0,1));$path=$root.'/storage/logs/observability/metrics-'.$shard.'.json';$file=new JsonFile($path);$data=$file->readObject();$data[$key]=['count'=>9,'sum'=>0.9,'buckets'=>array_fill(0,12,9),'recent'=>[intdiv(time(),300)-12=>[9,0.9]]];$file->writeObject($data);
    check($m->recentSummary()['components']['database']['count']===0&&$m->snapshot()[$key]['count']===9,'expired window removed from report but cumulative counter retained');
    $m->observe('database','query','success',0.1);check($m->recentSummary()['components']['database']['count']===1&&$m->snapshot()[$key]['count']===10&&count($m->snapshot()[$key]['recent'])===1,'new sample prunes expired bucket without resetting cumulative history');
    $oldKey='database|transaction|success';$shard=hexdec(substr(hash('sha256',$oldKey),0,1));$file=new JsonFile($root.'/storage/logs/observability/metrics-'.$shard.'.json');$data=$file->readObject();$data[$oldKey]=['count'=>2,'sum'=>0.1,'buckets'=>array_fill(0,12,2)];$file->writeObject($data);$m->observe('database','transaction','success',0.1);check($m->snapshot()[$oldKey]['count']===3&&$m->recentSummary()['components']['database']['operations']['transaction']['count']===1,'1.4.5 metric files remain readable and incrementable');
    $monitor=new OperationsMonitor($root,$cfg);$report=$monitor->report();check(isset($report['recent']['components']['ai'])&&in_array('recent_integration_failures',$report['alerts'],true),'unified report triggers recent failure alert');
    file_put_contents($root.'/storage/logs/observability/report.json','{bad');$cached=$monitor->cachedReport();check(isset($cached['recent']['components']),'corrupt diagnostic cache rebuilt');
    $prom=$monitor->prometheus();check(str_contains($prom,'cajeer_ai_duration_seconds_count{operation="openai.responses",outcome="rejected"} 1')&&str_contains($prom,'cajeer_recent_operation_failures{component="integration"} 5'),'Prometheus exports real component histograms and window failures');
    putenv('OBSERVABILITY_OTLP_ENDPOINT=http://127.0.0.1:1/trace');putenv('OBSERVABILITY_TRACE_SAMPLE_RATE=1');$parent=TraceContext::start();$previous=TraceScope::enter($parent);
    try{Operation::measure('ai','openai.responses',static fn()=>true,$root);}finally{TraceScope::leave($previous);}
    $spans=glob($root.'/storage/logs/observability/traces/span-*.json');$span=json_decode(file_get_contents(end($spans)),true,512,JSON_THROW_ON_ERROR);check($span['name']==='ai.provider'&&$span['traceId']===$parent->traceId&&$span['parentSpanId']===$parent->spanId,'new provider trace correlates with parent without payload');
    putenv('OBSERVABILITY_OTLP_ENDPOINT=');
    if(in_array('--database',$argv,true)){
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite required');
        $pdo=$db->connection();foreach(glob($source.'/migrations/sqlite/*.sql') as $file)$pdo->exec(file_get_contents($file));
        $now=time();$pdo->prepare('INSERT INTO ce_forum_analytics_events(id,event_name,created_at) VALUES (?,?,?)')->execute([str_repeat('a',32),'forum_thread_created',$now-1000]);
        $a=$probe->analytics();check($a['native_pending']===1&&$a['native_oldest_age_seconds']>=1000&&!$a['ok'],'real SQL native analytics backlog and age detected');
        $rollup=new CajeerEngine\Forum\Service\ForumAnalyticsRollup($db);check($rollup->aggregateBatch()===1&&$probe->analytics()['native_pending']===0,'real SQL rollup clears backlog');
        check($m->recentSummary()['components']['analytics']['operations']['native.rollup']['count']===2,'actual rollup instrumented alongside test observation');
        $pdo->exec('UPDATE ce_forum_ai_admission_state SET cooldown_until=' . ($now+60).' WHERE id=1');$ai=$probe->aiAdmission();check($ai['cooldown_seconds']>=50&&$ai['queued']===0,'real SQL AI cooldown and admission aggregate');
    }
    $m->flush();echo "Unified observability regression complete\n";
}finally{Metrics::flushAll();foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}
