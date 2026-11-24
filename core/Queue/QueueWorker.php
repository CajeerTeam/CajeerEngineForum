<?php
declare(strict_types=1);
namespace CajeerEngine\Queue;

use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Search\SearchIndexer;

final class QueueWorker
{
    private JobHandlerRegistry $handlers;
    private bool $stopping=false;
    private ?\CajeerEngine\Forum\Service\ForumRuntimeJobs $forumJobs=null;
    public function __construct(private readonly ManagedQueueDriverInterface $queue,private readonly SearchIndexer $searchIndexer,private readonly WebhookRepository $webhooks,private readonly WebhookDispatcher $dispatcher,private readonly ?\CajeerEngine\Extension\Runtime\PlatformServices $platform=null,private readonly ?\CajeerEngine\Analytics\ServerEvents $analytics=null,
        private readonly ?\CajeerEngine\Analytics\Reporting\AnalyticsHub $analyticsHub=null,
        private readonly ?\CajeerEngine\AI\AiTranslationStudio $translationStudio=null,
        private readonly ?\CajeerEngine\AI\AiSupportCenter $supportCenter=null,
        private readonly ?\CajeerEngine\AI\AiBackgroundAutomation $aiAutomations=null,
        private readonly ?\CajeerEngine\Database\DatabaseManager $database=null)
    {
        $this->handlers=new JobHandlerRegistry();
        $this->handlers->register('ai.automation.execute', function (array $payload, JobContext $context): void {
            if ($this->aiAutomations === null || !is_string($payload['run_id'] ?? null)) throw new \RuntimeException('AI Background Automation unavailable');
            $context->heartbeat();
            $this->aiAutomations->execute($payload['run_id'], $context);
            $context->heartbeat();
        });
        $this->handlers->register('ai.support.reply', function ($payload, JobContext $ctx): void {
            if ($this->supportCenter === null || !is_string($payload['ticket_id'] ?? null)) throw new \RuntimeException('AI Support job unavailable.');
            $ctx->heartbeat();
            $this->supportCenter->autoReply($payload['ticket_id']);
            $ctx->heartbeat();
        });
        $this->handlers->register('ai.translation.propose', function ($payload, JobContext $ctx): void {
            if ($this->translationStudio === null || !is_string($payload['item_id'] ?? null)) throw new \RuntimeException('AI Translation Studio job unavailable');
            $ctx->heartbeat();
            $this->translationStudio->runBatchItem($payload['item_id'], $ctx->job->attempts, $ctx->job->maxAttempts);
            $ctx->heartbeat();
        });
        $this->handlers->register('analytics.report_sync', function ($p): void {
            if ($this->analyticsHub === null) throw new \RuntimeException('Analytics reporting unavailable');
            $this->analyticsHub->sync($p);
        });
        $this->handlers->register('analytics.deliver', fn($p, $c) => ($this->analytics ?? \CajeerEngine\Analytics\ServerEvents::fromRoot($this->webhooks->rootPath(), $this->queue))->deliver($p));
        $this->handlers->register('log.message',fn($p,$c)=>$this->logMessage($p,$c));
        $this->handlers->register('search.index_all',fn()=> $this->searchIndexer->indexAll());
        $this->handlers->register('webhooks.dispatch_pending',function(){if(!$this->webhooks->enqueuePending($this->queue,50)['ok'])throw new \RuntimeException('Outbox handoff failed; events retained for retry');});
        $this->handlers->register('webhook.dispatch',fn($p,$c)=>$this->dispatchWebhookJob($p,$c));
        $this->handlers->register('webhook.deliver',fn($p,$c)=>$this->deliver($p,$c));
        if($database!==null){$this->forumJobs=new \CajeerEngine\Forum\Service\ForumRuntimeJobs($database,$queue,$platform);$this->forumJobs->register($this->handlers);}
    }
    public function registry(): JobHandlerRegistry {return $this->handlers;}
    public function stop(): void {$this->stopping=true;}
    public function work(?string $queue=null,int $limit=10): array
    {
        $root=$this->webhooks->rootPath();$metrics=new \CajeerEngine\Observability\Metrics($root);\CajeerEngine\Observability\OperationsMonitor::marker($root,'worker','running');
        $processed=0;$failed=0;$errors=[];$lost=0;$signals=PHP_SAPI==='cli'&&function_exists('pcntl_signal')&&function_exists('pcntl_alarm');
        if($signals){$async=pcntl_async_signals(true);$old=[];foreach([SIGTERM,SIGINT,SIGALRM]as $s)$old[$s]=pcntl_signal_get_handler($s);pcntl_signal(SIGTERM,fn()=> $this->stop());pcntl_signal(SIGINT,fn()=> $this->stop());pcntl_signal(SIGALRM,static function(){throw new \RuntimeException('Job execution timeout');});}
        $forumProcessed=0;$mediaStats=['image'=>'disabled','replica'=>'disabled'];$mailStats=['sent'=>0,'failed'=>0,'cancelled'=>0];
        try {
            $forumDbReady=true;$backendUnavailable=false;$recovered=0;
            if ($this->database!==null && !$this->stopping) {
                try { $this->database->connection()->query('SELECT 1')->fetchColumn(); }
                catch (\Throwable) { $forumDbReady=false;$this->database->disconnect();$failed++;$errors[]=['name'=>'forum.database','error'=>'Database unavailable; durable forum work deferred']; }
            }
            if($this->forumJobs!==null)$this->forumJobs->stats=[];
            if(($queue===null||$queue==='default')&&$this->forumJobs!==null&&$forumDbReady&&!$this->stopping){
                try{if(!$this->forumJobs->schedule()['ok'])throw new \RuntimeException('Enqueue incomplete');}
                catch(\Throwable){$failed++;$backendUnavailable=true;$errors[]=['name'=>'forum.schedule','error'=>'Background handoff incomplete; durable SQL work retained'];}
            }
            if($this->queue instanceof LeaseQueueDriverInterface&&!$this->stopping){
                try{$recovered=$this->queue->recover($queue,100);}
                catch(\Throwable){$failed++;$backendUnavailable=true;$errors[]=['name'=>'queue.recover','error'=>'Reservation recovery unavailable'];}
            }
            for($i=0;$i<max(1,min(1000,$limit))&&$forumDbReady&&!$this->stopping;$i++) {
                try{$job=$this->queue->pop($queue);}catch(\Throwable){$failed++;$backendUnavailable=true;$errors[]=['name'=>'queue.pop','error'=>'Queue reservation unavailable'];break;}if($job===null)break;$context=new JobContext($job,$this->queue);$started=hrtime(true);$wall=microtime(true);$trace=\CajeerEngine\Observability\TraceScope::child();$previousTrace=\CajeerEngine\Observability\TraceScope::enter($trace);$outcome='failure';$handlerFinished=false;
                try {
                    $context->heartbeat();
                    if($signals){$timeout=max(1,(int)(getenv('QUEUE_JOB_TIMEOUT_SECONDS')?:120));$lease=$job->leaseUntil===null?$timeout:max(1,$job->leaseUntil-time()-1);pcntl_alarm(min($timeout,$lease));}
                    try {if(str_starts_with($job->name,'extension.')){if($this->platform===null)throw new \RuntimeException('Extension runtime unavailable');$this->platform->handleJob($job->name,$job->payload,$context);}else $this->handlers->handle($job,$context);}
                    finally {if($signals)pcntl_alarm(0);}
                    $handlerFinished=true;$context->heartbeat();$this->queue->complete($job);$processed++;$outcome='success';
                }catch(\Throwable $e){
                    if($e instanceof \CajeerEngine\Database\DatabaseUnavailable || ($e instanceof \PDOException && \CajeerEngine\Database\DatabaseUnavailable::causedBy($e))){$forumDbReady=false;$this->database?->disconnect();$backendUnavailable=true;}
                    $failed++;$errors[]=['id'=>$job->id,'name'=>$job->name,'error'=>$handlerFinished?'Completion not confirmed':'Job execution failed'];
                    // Never deliberately retry completed side effects after an uncertain ACK.
                    if($handlerFinished||!$forumDbReady){$lost++;$outcome='lost';$backendUnavailable=true;}
                    else {try{$this->queue->fail($job,'Job execution failed',QueueOptions::backoff($job->attempts));}catch(\Throwable){$lost++;$outcome='lost';$backendUnavailable=true;$errors[]=['id'=>$job->id,'error'=>'Cannot persist failure'];}}
                }finally{\CajeerEngine\Observability\TraceScope::leave($previousTrace);$duration=(hrtime(true)-$started)/1e9;$metrics->observe('job',$job->name,$outcome,$duration);(new \CajeerEngine\Observability\TraceExporter($root))->record($trace,'job',$job->name,$wall,$duration,$outcome==='success');(new \CajeerEngine\Runtime\RuntimeLogger($root))->info('Background job finished',$trace->fields()+['job_id'=>$job->id,'status'=>$outcome,'duration_ms'=>round($duration*1000,3)]);\CajeerEngine\Observability\OperationsMonitor::marker($root,'worker','running');}
            }
        }finally{if($signals){pcntl_alarm(0);foreach($old as $s=>$handler)pcntl_signal($s,$handler);pcntl_async_signals($async);}}
        $stats=$this->forumJobs?->stats??[];$forumProcessed+=$stats['notifications']??0;foreach($stats['mail']??[] as $key=>$n)$mailStats[$key]+=$n;$mediaStats=array_replace($mediaStats,$stats['media']??[]);
        try{$diagnostics=$this->queue->diagnostics();if(($diagnostics['connected']??true)===false)$backendUnavailable=true;}catch(\Throwable){$backendUnavailable=true;$diagnostics=['connected'=>false,'error'=>'Queue unavailable'];}
        \CajeerEngine\Observability\OperationsMonitor::marker($root,'worker',($failed||$backendUnavailable)?'failure':'success');
        return ['ok'=>$failed===0&&!$backendUnavailable,'backend_unavailable'=>$backendUnavailable||!$forumDbReady,'recovered'=>$recovered,'processed'=>$processed,'forum_extensions'=>$stats['extensions']??[],'forum_notifications_processed'=>$forumProcessed,'forum_mail'=>$mailStats,'forum_attachments_cleaned'=>$stats['attachments']??0,'forum_drafts_cleaned'=>$stats['drafts']??0,'forum_spam_cleaned'=>$stats['spam']??0,'forum_recycle_purged'=>$stats['recycle']??0,'forum_media'=>$mediaStats,'platform_workflows_processed'=>$stats['workflows']??0,'failed'=>$failed,'lost_reservations'=>$lost,'stopping'=>$this->stopping,'errors'=>$errors,'diagnostics'=>$diagnostics];
    }
    private function logMessage(array $payload,JobContext $context): void
    {
        $path=$this->webhooks->rootPath().'/storage/logs/queue.log';\CajeerEngine\Update\OperationFiles::dir(dirname($path),0770);
        $h=fopen($path,'c+');if(!$h)throw new \RuntimeException('Cannot open job log');
        try{if(!flock($h,LOCK_EX))throw new \RuntimeException('Cannot lock job log');$marker='['.$context->job->id.']';while(($line=fgets($h))!==false)if(str_contains($line,' '.$marker.' ')&&str_ends_with($line,"\n"))return;
            $line='['.gmdate(DATE_ATOM).'] '.$marker.' '.str_replace(["\r","\n"],' ',(string)($payload['message']??'empty')).PHP_EOL;
            if(fseek($h,0,SEEK_END)!==0||fwrite($h,$line)!==strlen($line)||!fflush($h)||!fsync($h))throw new \RuntimeException('Cannot persist job log');
        }finally{fclose($h);}
    }
    private function dispatchWebhookJob(array $p,JobContext $c): void
    {
        $event=(string)($p['event']??'manual.test');$body=is_array($p['payload']??null)?$p['payload']:[];
        foreach($this->webhooks->matching($event)as $w){$c->heartbeat();$this->queue->push('webhook.deliver',['event'=>$event,'payload'=>$body,'webhook_id'=>$w['id'],'event_id'=>$c->job->id,'_idempotency_key'=>'webhook:'.$c->job->id.':'.$w['id']],$c->job->queue);}
    }
    private function deliver(array $p,JobContext $c): void
    {
        $id=(string)($p['webhook_id']??'');$event=(string)($p['event']??'');$eventId=(string)($p['event_id']??$c->job->id);
        $w=$this->webhooks->find($id);if(!$w||!($w['enabled']??false))return;
        $this->webhooks->deliverOnce($id,$eventId,function()use($w,$event,$p,$eventId,$c){$c->heartbeat();$r=$this->dispatcher->dispatchWebhook($w,$event,is_array($p['payload']??null)?$p['payload']:[],$eventId);if(!$r['ok'])throw new \RuntimeException('Webhook delivery failed (HTTP '.(int)$r['status_code'].')');return $r;});
    }
}
