<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\Repository\{NotificationRepository,WatchRepository,ThreadRepository,ForumMediaRepository};
use CajeerEngine\Queue\{ManagedQueueDriverInterface,JobHandlerRegistry,JobContext};

/** Redis jobs are wakeups for durable SQL outboxes, not the only copy of a publication or delivery. */
final class ForumRuntimeJobs
{
    public array $stats=[];
    public function __construct(private readonly DatabaseManager $db,private readonly ManagedQueueDriverInterface $queue,private readonly ?\CajeerEngine\Extension\Runtime\PlatformServices $platform=null) {}
    private function tx(): ForumTransaction {return new ForumTransaction($this->db,new AuditLogRepository($this->db),$this->platform);}
    public function register(JobHandlerRegistry $registry): void
    {
        if($this->platform!==null)$registry->register('forum.extensions.dispatch',function(array $p,JobContext $ctx){$ctx->heartbeat();foreach((new ForumExtensionOutbox($this->db))->drain($this->platform,20) as $key=>$n)$this->stats['extensions'][$key]=($this->stats['extensions'][$key]??0)+$n;});
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
            $total=0;
            do{$ctx->heartbeat();$n=ForumAiChat::pruneExpiredSearch($this->db,100);$total+=$n;}
            while($n===100 && $total<500);
            $this->stats['ai_search_cleaned']=$total;
            $history=ForumAiChat::prunePrivateHistory($this->db,100);
            $this->stats['ai_private_retention']=$history;
        });
        $registry->register('forum.analytics.rollup',$analyticsJob);
        $registry->register('forum.analytics.cleanup',$analyticsJob);
        $registry->register('forum.notifications.dispatch',function(array $p,JobContext $ctx){$ctx->heartbeat();$this->stats['notifications']=($this->stats['notifications']??0)+$this->tx()->notifications()->dispatch(100);});
        $registry->register('forum.mail.dispatch',function(array $p,JobContext $ctx){$ctx->heartbeat();$stats=$this->tx()->watches()->dispatch(5);foreach($stats as $key=>$n)$this->stats['mail'][$key]=($this->stats['mail'][$key]??0)+$n;if($stats['failed'])throw new \RuntimeException('Forum mail delivery failed; SQL retry scheduled');});
        foreach(['image'=>'processImage','replica'=>'syncReplica'] as $kind=>$method)$registry->register('forum.media.'.$kind,function(array $p,JobContext $ctx)use($kind,$method){$ctx->heartbeat();$result=$this->tx()->media()->$method();$this->stats['media'][$kind]=$result;if($result==='failed')throw new \RuntimeException('Forum media processing failed; SQL retry scheduled');});
    }
    /** Idempotent bounded wakeups. A lost Redis job is reconstructed from SQL on the next tick. */
    public function schedule(): void
    {
        $now=time();$tick=intdiv($now,10);$jobs=[];
        if($this->db->tableExists('ce_forum_analytics_state') && intdiv($now,60)!==intdiv($now-10,60))$jobs[]='forum.analytics.rollup';
        if($this->db->tableExists('ce_forum_ai_conversations') && intdiv($now,900)!==intdiv($now-10,900))$jobs[]='forum.ai.search.cleanup';
        if($this->platform!==null&&$this->db->tableExists('ce_forum_extension_events')&&(new ForumExtensionOutbox($this->db))->pending())$jobs[]='forum.extensions.dispatch';
        if($this->db->tableExists('ce_forum_notifications')&&(new NotificationRepository($this->db))->pending(1))$jobs[]='forum.notifications.dispatch';
        if($this->db->tableExists('ce_forum_mail_delivery')&&(new WatchRepository($this->db))->candidate($now)!==null)$jobs[]='forum.mail.dispatch';
        if($this->db->tableExists('ce_forum_media_state')){
            $repo=new ForumMediaRepository($this->db);
            if(\CajeerEngine\Forum\Content\ForumImageProcessor::available()&&$repo->nextImage($now)!==null)$jobs[]='forum.media.image';
            // Replica discovery itself creates a durable SQL ledger; run at a slower cadence.
            if(getenv('FORUM_MEDIA_S3_REPLICA')==='true')$jobs[]='forum.media.replica';
        }
        foreach($jobs as $name){$slot=$name==='forum.ai.search.cleanup'?intdiv($now,900):($name==='forum.analytics.rollup'?intdiv($now,60):($name==='forum.media.replica'?intdiv($now,30):$tick));$this->queue->push($name,['_idempotency_key'=>$name.':'.$slot,'_max_attempts'=>3],'default');}
    }
}
