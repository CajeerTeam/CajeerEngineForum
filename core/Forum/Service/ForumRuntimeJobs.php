<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\Repository\{NotificationRepository,WatchRepository,ThreadRepository,ForumMediaRepository};
use CajeerEngine\Queue\{ManagedQueueDriverInterface,JobHandlerRegistry,JobContext};

/** Queue wakeups for durable SQL outboxes, independent of transport. */
final class ForumRuntimeJobs
{
    public array $stats=[];
    public function __construct(private readonly DatabaseManager $db,private readonly ManagedQueueDriverInterface $queue,private readonly ?\CajeerEngine\Extension\Runtime\PlatformServices $platform=null) {}
    private function tx(): ForumTransaction {return new ForumTransaction($this->db,new AuditLogRepository($this->db),$this->platform);}
    public function register(JobHandlerRegistry $registry): void
    {
        if($this->platform!==null)$registry->register('forum.extensions.dispatch',function(array $p,JobContext $ctx){$ctx->heartbeat();foreach((new ForumExtensionOutbox($this->db))->drain($this->platform,20) as $key=>$n)$this->stats['extensions'][$key]=($this->stats['extensions'][$key]??0)+$n;});
        if($this->platform!==null)$registry->register('forum.extensions.cleanup',function(array $p,JobContext $ctx):void{$ctx->heartbeat();$this->stats['extension_cleanup']=(new ForumExtensionOutbox($this->db))->cleanup();});
        foreach(['drafts'=>['drafts','drafts'], 'recycle'=>['recycle','recycle'], 'attachments'=>['attachments','attachments'], 'spam'=>['antiSpam','spam']] as $kind=>[$service,$stat]) {
            $name=$kind==='recycle'?'forum.recycle.purge':'forum.'.$kind.'.cleanup';
            $registry->register($name,function(array $p,JobContext $ctx)use($service,$stat):void{$ctx->heartbeat();$this->stats[$stat]=($this->stats[$stat]??0)+$this->tx()->$service()->cleanup();});
        }
        // Old queued cleanup wakeups remain supported across rolling deployments.
        $analyticsJob=function(array $p,JobContext $ctx):void{
            $rollup=new ForumAnalyticsRollup($this->db);$count=0;$started=microtime(true);
            do {
                $ctx->heartbeat();$batch=$rollup->aggregateBatch();$count+=$batch;
            } while($batch===ForumAnalyticsRollup::BATCH_SIZE && $count<10000 && microtime(true)-$started<3.0);
            $ctx->heartbeat();$pruned=$rollup->cleanup();
            $this->stats['analytics']=['aggregated'=>$count]+$pruned;
        };
        // SQL retention of abandoned search responses is independent of visitor traffic.
        $registry->register('forum.ai.search.cleanup',function(array $p,JobContext $ctx):void{
            if(!$this->db->tableExists('ce_forum_ai_conversations'))return;
            $this->stats['ai_admission_cleaned']=(new ForumAiAdmission($this->db))->cleanup();
            $ctx->heartbeat();
            $cost=new \CajeerEngine\AI\AiCostControl($this->db,new \CajeerEngine\AI\AiSettings($this->db->config()->rootPath()));
            $this->stats['ai_cost_recovered']=$this->db->transaction(fn(\PDO $pdo):int=>$cost->reap($pdo,time(),100));
            $total=0;
            do{$ctx->heartbeat();$n=ForumAiChat::pruneExpiredSearch($this->db,100);$total+=$n;}
            while($n===100 && $total<500);
            $this->stats['ai_search_cleaned']=$total;
            $history=ForumAiChat::prunePrivateHistory($this->db,100);
            $this->stats['ai_private_retention']=$history;
        });
        $registry->register('platform.workflows.run',function(array $p,JobContext $ctx):void{
            if(!$this->db->tableExists('ce_platform_workflows'))return;
            $ctx->heartbeat();$this->stats['workflows']=($this->stats['workflows']??0)+(new PlatformWorkflows($this->db))->drain();
        });
        $registry->register('forum.analytics.rollup',$analyticsJob);
        $registry->register('forum.analytics.cleanup',$analyticsJob);
        $registry->register('forum.notifications.dispatch',function(array $p,JobContext $ctx){$ctx->heartbeat();$this->stats['notifications']=($this->stats['notifications']??0)+$this->tx()->notifications()->dispatch(100);});
        $registry->register('forum.mail.dispatch',function(array $p,JobContext $ctx){$ctx->heartbeat();$stats=$this->tx()->watches()->dispatch(5);foreach($stats as $key=>$n)$this->stats['mail'][$key]=($this->stats['mail'][$key]??0)+$n;if($stats['failed'])throw new \RuntimeException('Forum mail delivery failed; SQL retry scheduled');});
        foreach(['image'=>'processImage','replica'=>'syncReplica'] as $kind=>$method)$registry->register('forum.media.'.$kind,function(array $p,JobContext $ctx)use($kind,$method){$ctx->heartbeat();$result=$this->tx()->media()->$method();$this->stats['media'][$kind]=$result;if($result==='failed')throw new \RuntimeException('Forum media processing failed; SQL retry scheduled');});
    }
    /** Stable UTC slots; a missed tick is reconstructed on the next invocation. */
    public function schedule(?int $now=null): array
    {
        $now??=time();$jobs=[];
        if($this->db->tableExists('ce_platform_workflows')&&(new PlatformWorkflows($this->db))->pending($now))$jobs['platform.workflows.run']=10;
        if($this->db->tableExists('ce_forum_analytics_state'))$jobs['forum.analytics.rollup']=60;
        if($this->db->tableExists('ce_forum_ai_conversations'))$jobs['forum.ai.search.cleanup']=900;
        if($this->platform!==null&&$this->db->tableExists('ce_forum_extension_events')){
            if((new ForumExtensionOutbox($this->db))->pending())$jobs['forum.extensions.dispatch']=10;
            $jobs['forum.extensions.cleanup']=3600;
        }
        if($this->db->tableExists('ce_forum_notifications')&&(new NotificationRepository($this->db))->pending(1))$jobs['forum.notifications.dispatch']=10;
        if($this->db->tableExists('ce_forum_mail_delivery')&&(new WatchRepository($this->db))->candidate($now)!==null)$jobs['forum.mail.dispatch']=10;
        if($this->db->tableExists('ce_forum_media_state')){
            $repo=new ForumMediaRepository($this->db);
            if(\CajeerEngine\Forum\Content\ForumImageProcessor::available()&&$repo->nextImage($now)!==null)$jobs['forum.media.image']=10;
            if(getenv('FORUM_MEDIA_S3_REPLICA')==='true')$jobs['forum.media.replica']=30;
        }
        if($this->db->tableExists('ce_forum_drafts'))$jobs['forum.drafts.cleanup']=60;
        if($this->db->columnExists('ce_forum_threads','deleted_at'))$jobs['forum.recycle.purge']=60;
        if($this->db->tableExists('ce_forum_attachments'))$jobs['forum.attachments.cleanup']=60;
        if($this->db->tableExists('ce_forum_spam_events'))$jobs['forum.spam.cleanup']=60;
        $ids=[];$errors=[];
        foreach($jobs as $name=>$interval){
            try{$ids[$name]=$this->queue->push($name,['_idempotency_key'=>$name.':unified:'.intdiv($now,$interval),'_max_attempts'=>3,'_coalesce_key'=>$name],'default');}
            catch(\Throwable){$errors[]=$name;}
        }
        return ['ok'=>$errors===[],'jobs'=>$ids,'failed_jobs'=>$errors];
    }
}
