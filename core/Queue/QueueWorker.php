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
    public function __construct(private readonly ManagedQueueDriverInterface $queue,private readonly SearchIndexer $searchIndexer,private readonly WebhookRepository $webhooks,private readonly WebhookDispatcher $dispatcher,private readonly ?\CajeerEngine\Extension\Runtime\PlatformServices $platform=null,private readonly ?\CajeerEngine\Database\DatabaseManager $database=null)
    {
        $this->handlers=new JobHandlerRegistry();
        $this->handlers->register('log.message',fn($p,$c)=>$this->logMessage($p,$c));
        $this->handlers->register('search.index_all',fn()=> $this->searchIndexer->indexAll());
        $this->handlers->register('webhooks.dispatch_pending',function(){if(!$this->webhooks->enqueuePending($this->queue,50)['ok'])throw new \RuntimeException('Outbox handoff failed; events retained for retry');});
        $this->handlers->register('webhook.dispatch',fn($p,$c)=>$this->dispatchWebhookJob($p,$c));
        $this->handlers->register('webhook.deliver',fn($p,$c)=>$this->deliver($p,$c));
        if($database!==null){$this->forumJobs=new \CajeerEngine\Forum\Service\ForumRuntimeJobs($database,$queue);$this->forumJobs->register($this->handlers);}
    }
    public function registry(): JobHandlerRegistry {return $this->handlers;}
    public function stop(): void {$this->stopping=true;}
    public function work(?string $queue=null,int $limit=10): array
    {
        $root=$this->webhooks->rootPath();$metrics=new \CajeerEngine\Observability\Metrics($root);\CajeerEngine\Observability\OperationsMonitor::marker($root,'worker','running');
        $processed=0;$failed=0;$errors=[];$lost=0;$signals=PHP_SAPI==='cli'&&function_exists('pcntl_signal')&&function_exists('pcntl_alarm');
        if($signals){$async=pcntl_async_signals(true);$old=[];foreach([SIGTERM,SIGINT,SIGALRM]as $s)$old[$s]=pcntl_signal_get_handler($s);pcntl_signal(SIGTERM,fn()=> $this->stop());pcntl_signal(SIGINT,fn()=> $this->stop());pcntl_signal(SIGALRM,static function(){throw new \RuntimeException('Job execution timeout');});}
        $spamCleaned=0;$forumProcessed=0;$recyclePurged=0;$attachmentsCleaned=0;$draftsCleaned=0;$mediaStats=['image'=>'disabled','replica'=>'disabled'];$mailStats=['sent'=>0,'failed'=>0,'cancelled'=>0];
        try {
            $distributed=false;
            if($this->forumJobs!==null)$this->forumJobs->stats=[];
            if(($queue===null||$queue==='default')&&$this->queue instanceof RedisQueueDriver&&$this->forumJobs!==null&&!$this->stopping){
                try{$this->forumJobs->schedule();$distributed=true;}catch(\Throwable $e){$failed++;$errors[]=['name'=>'forum.redis.schedule','error'=>'Redis wakeup failed; draining SQL outboxes'];}
            }
            // Non-Redis workers (or failed Redis handoff) drain durable SQL directly.
            if (!$distributed && ($queue===null || $queue==='default') && $this->database!==null && $this->database->tableExists('ce_forum_notifications')) {
                try {
                    $tx=new \CajeerEngine\Forum\Service\ForumTransaction($this->database,new \CajeerEngine\Database\Repository\AuditLogRepository($this->database));
                    if($signals)pcntl_alarm(max(1,(int)(getenv('QUEUE_JOB_TIMEOUT_SECONDS')?:120)));
                    try{$forumProcessed=$tx->notifications()->dispatch(10*max(1,min(10,$limit)));}finally{if($signals)pcntl_alarm(0);}
                }catch(\Throwable $e){$failed++;$errors[]=['name'=>'forum.notifications.dispatch','error'=>QueueOptions::error($e->getMessage())];}
            }
            if (!$distributed && ($queue===null || $queue==='default') && $this->database!==null && $this->database->tableExists('ce_forum_mail_delivery') && !$this->stopping) {
                try {
                    $tx=new \CajeerEngine\Forum\Service\ForumTransaction($this->database,new \CajeerEngine\Database\Repository\AuditLogRepository($this->database));
                    if($signals)pcntl_alarm(max(1,min(240,(int)(getenv('QUEUE_JOB_TIMEOUT_SECONDS')?:120))));
                    try{$mailStats=$tx->watches()->dispatch(min(5,$limit));}finally{if($signals)pcntl_alarm(0);}
                    if($mailStats['failed']>0){$failed+=$mailStats['failed'];$errors[]=['name'=>'forum.mail.dispatch','error'=>'SMTP delivery failed; retry scheduled'];}
                }catch(\Throwable $e){$failed++;$errors[]=['name'=>'forum.mail.dispatch','error'=>'Forum mail processing failed'];}
            }
            if (($queue===null || $queue==='default') && $this->database!==null && $this->database->tableExists('ce_forum_drafts') && !$this->stopping) {
                try{$tx=new \CajeerEngine\Forum\Service\ForumTransaction($this->database,new \CajeerEngine\Database\Repository\AuditLogRepository($this->database));$draftsCleaned=$tx->drafts()->cleanup();}
                catch(\Throwable $e){$failed++;$errors[]=['name'=>'forum.drafts.cleanup','error'=>'Draft cleanup failed'];}
            }
            if (($queue===null || $queue==='default') && $this->database!==null && $this->database->columnExists('ce_forum_threads','deleted_at') && !$this->stopping) {
                try{$tx=new \CajeerEngine\Forum\Service\ForumTransaction($this->database,new \CajeerEngine\Database\Repository\AuditLogRepository($this->database));$recyclePurged=$tx->recycle()->cleanup();}
                catch(\Throwable $e){$failed++;$errors[]=['name'=>'forum.recycle.purge','error'=>'Recycle bin cleanup failed'];}
            }
            if (($queue===null || $queue==='default') && $this->database!==null && $this->database->tableExists('ce_forum_attachments') && !$this->stopping) {
                try{$tx=new \CajeerEngine\Forum\Service\ForumTransaction($this->database,new \CajeerEngine\Database\Repository\AuditLogRepository($this->database));$attachmentsCleaned=$tx->attachments()->cleanup();}
                catch(\Throwable $e){$failed++;$errors[]=['name'=>'forum.attachments.cleanup','error'=>'Attachment cleanup failed'];}
            }
            if (!$distributed && ($queue===null || $queue==='default') && $this->database!==null && $this->database->tableExists('ce_forum_media_state') && !$this->stopping) {
                foreach(['image'=>'processImage','replica'=>'syncReplica'] as $kind=>$method){try{
                    $tx=new \CajeerEngine\Forum\Service\ForumTransaction($this->database,new \CajeerEngine\Database\Repository\AuditLogRepository($this->database));
                    if($signals)pcntl_alarm(max(90,min(240,(int)(getenv('QUEUE_JOB_TIMEOUT_SECONDS')?:120))));
                    try{$mediaStats[$kind]=$tx->media()->$method();}finally{if($signals)pcntl_alarm(0);}
                    if($mediaStats[$kind]==='failed'){$failed++;$errors[]=['name'=>'forum.media.'.$kind,'error'=>'Media processing failed; retry scheduled'];}
                }catch(\Throwable $e){$failed++;$mediaStats[$kind]='failed';$errors[]=['name'=>'forum.media.'.$kind,'error'=>'Media processing failed'];}}
            }
            if (($queue===null || $queue==='default') && $this->database!==null && $this->database->tableExists('ce_forum_spam_events') && !$this->stopping) {
                try{$tx=new \CajeerEngine\Forum\Service\ForumTransaction($this->database,new \CajeerEngine\Database\Repository\AuditLogRepository($this->database));$spamCleaned=$tx->antiSpam()->cleanup();}
                catch(\Throwable $e){$failed++;$errors[]=['name'=>'forum.spam.cleanup','error'=>'Spam counter cleanup failed'];}
            }
            for($i=0;$i<max(1,min(1000,$limit))&&!$this->stopping;$i++) {
                try{$job=$this->queue->pop($queue);}catch(\Throwable $e){$failed++;$errors[]=['name'=>'queue.pop','error'=>QueueOptions::error($e->getMessage())];break;}if($job===null)break;$context=new JobContext($job,$this->queue);$started=hrtime(true);$wall=microtime(true);$trace=\CajeerEngine\Observability\TraceScope::child();$previousTrace=\CajeerEngine\Observability\TraceScope::enter($trace);$outcome='failure';
                try {
                    $context->heartbeat();
                    if($signals){$timeout=max(1,(int)(getenv('QUEUE_JOB_TIMEOUT_SECONDS')?:120));$lease=$job->leaseUntil===null?$timeout:max(1,$job->leaseUntil-time()-1);pcntl_alarm(min($timeout,$lease));}
                    try {if(str_starts_with($job->name,'extension.')){if($this->platform===null)throw new \RuntimeException('Extension runtime unavailable');$this->platform->handleJob($job->name,$job->payload,$context);}else $this->handlers->handle($job,$context);}
                    finally {if($signals)pcntl_alarm(0);}
                    $context->heartbeat();$this->queue->complete($job);$processed++;$outcome='success';
                }catch(\Throwable $e){$failed++;$errors[]=['id'=>$job->id,'name'=>$job->name,'error'=>QueueOptions::error($e->getMessage())];try{$this->queue->fail($job,$e->getMessage(),QueueOptions::backoff($job->attempts));}catch(\Throwable $ack){$lost++;$outcome='lost';$errors[]=['id'=>$job->id,'error'=>'Cannot persist failure: '.QueueOptions::error($ack->getMessage())];}}finally{\CajeerEngine\Observability\TraceScope::leave($previousTrace);$duration=(hrtime(true)-$started)/1e9;$metrics->observe('job',$job->name,$outcome,$duration);(new \CajeerEngine\Observability\TraceExporter($root))->record($trace,'job',$job->name,$wall,$duration,$outcome==='success');(new \CajeerEngine\Runtime\RuntimeLogger($root))->info('Background job finished',$trace->fields()+['job_id'=>$job->id,'status'=>$outcome,'duration_ms'=>round($duration*1000,3)]);\CajeerEngine\Observability\OperationsMonitor::marker($root,'worker','running');}
            }
        }finally{if($signals){pcntl_alarm(0);foreach($old as $s=>$handler)pcntl_signal($s,$handler);pcntl_async_signals($async);}}
        \CajeerEngine\Observability\OperationsMonitor::marker($root,'worker',$failed?'failure':'success');
        $stats=$this->forumJobs?->stats??[];$forumProcessed+=$stats['notifications']??0;foreach($stats['mail']??[] as $key=>$n)$mailStats[$key]+=$n;$mediaStats=array_replace($mediaStats,$stats['media']??[]);
        try{$diagnostics=$this->queue->diagnostics();}catch(\Throwable){$diagnostics=['connected'=>false,'error'=>'Queue unavailable'];}
        return ['processed'=>$processed,'forum_notifications_processed'=>$forumProcessed,'forum_mail'=>$mailStats,'forum_attachments_cleaned'=>$attachmentsCleaned,'forum_drafts_cleaned'=>$draftsCleaned,'forum_spam_cleaned'=>$spamCleaned,'forum_recycle_purged'=>$recyclePurged,'forum_media'=>$mediaStats,'failed'=>$failed,'lost_reservations'=>$lost,'stopping'=>$this->stopping,'errors'=>$errors,'diagnostics'=>$diagnostics];
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
