<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Extension\Runtime\PlatformServices;

/** Durable, at-least-once delivery. Only explicitly selected, metadata-only forum events. */
final readonly class ForumExtensionOutbox
{
    public const EVENTS = [
        'forum.thread.create'=>['thread_id','board_id','post_id'],
        'forum.post.reply'=>['thread_id','post_id'],
        'forum.post.edit'=>['thread_id','post_id'],
        'forum.thread.metadata'=>['thread_id'],
        'forum.thread.bulk'=>['thread_ids','action','board_id'],
        'forum.thread.merge'=>['target_id','source_ids'],
        'forum.thread.split'=>['source_id','target_id','post_ids'],
        'forum.post.move'=>['source_id','target_id','post_ids'],
        'forum.recycle.delete'=>['kind','id','board_id'],
        'forum.recycle.restore'=>['kind','id','board_id'],
        'forum.recycle.purge'=>['kind','id','board_id'],
        'forum.recycle.expire'=>['kind','id'],
        'forum.member.register'=>[],
        'forum.group.assign'=>['user_id','primary_group_id','secondary_group_ids'],
        'forum.moderation.approve'=>['kind','case_id','board_id'],
        'forum.moderation.reject'=>['kind','case_id','board_id'],
        'forum.report.resolve'=>['report_id'],
        'forum.moderate'=>['thread_id','action'],
        'forum.sanction.issue'=>['id','user_id','kind','board_id'],
        'forum.sanction.revoke'=>['id','user_id'],
        'forum.sanction.automatic'=>['id','user_id','kind'],
        'forum.sanction.appeal.decide'=>['id','status'],
    ];
    public function __construct(private DatabaseManager $db) {}

    public function record(string $event, ?string $actor, array $context,array $targets): void
    {
        if ($targets===[] || !array_key_exists($event,self::EVENTS)) return;
        $pdo=$this->db->connection();
        if (!$pdo->inTransaction()) throw new \LogicException('Forum extension event requires a transaction');
        $payload=array_intersect_key($context,array_flip(self::EVENTS[$event]));
        $payload['actor_id']=$actor;
        $stmt=$pdo->prepare('INSERT INTO ce_forum_extension_events (id,event_name,payload,created_at,due_at,targets,progress) VALUES (?,?,?,?,?,?,?)');
        $now=time();$stmt->execute([bin2hex(random_bytes(16)),$event,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$now,$now,json_encode($targets,JSON_THROW_ON_ERROR),'{}']);
    }

    /** Claim via compare-and-set, including expired leases. No plugin code in SQL transactions. */
    private function claim(): ?array
    {
        $pdo=$this->db->connection();$now=time();
        if($pdo->inTransaction())throw new \LogicException('Forum extension delivery requires a committed transaction');
        $s=$pdo->prepare("SELECT * FROM ce_forum_extension_events WHERE state='pending' AND due_at<=? AND lease_until<=? ORDER BY due_at,id LIMIT 8");$s->execute([$now,$now]);
        $rows=$s->fetchAll(\PDO::FETCH_ASSOC);$s->closeCursor();
        foreach($rows as $row){
            $token=bin2hex(random_bytes(16));
            $u=$pdo->prepare("UPDATE ce_forum_extension_events SET lease_token=?,lease_until=? WHERE id=? AND state='pending' AND due_at<=? AND lease_until<=?");
            $u->execute([$token,$now+300,$row['id'],$now,$now]);
            if($u->rowCount()===1){
                // The selected candidate may have been completed and released by another worker.
                // Read the current progress only after winning the fenced claim.
                $fresh=$pdo->prepare('SELECT * FROM ce_forum_extension_events WHERE id=? AND lease_token=?');$fresh->execute([$row['id'],$token]);
                $claimed=$fresh->fetch(\PDO::FETCH_ASSOC);$fresh->closeCursor();if($claimed!==false)return $claimed;
            }
        }
        return null;
    }

    /** One subscriber per claim; successful subscribers are never retried for another subscriber's failure. */
    public function drain(PlatformServices $platform,int $limit=10): array
    {
        $stats=['delivered'=>0,'retried'=>0,'dead'=>0,'skipped'=>0,'completed'=>0];
        for($i=0;$i<max(1,min(100,$limit));$i++){
            $row=$this->claim();if($row===null)break;
            $targets=json_decode($row['targets'],true,32,JSON_THROW_ON_ERROR);
            $done=json_decode($row['progress'],true,32,JSON_THROW_ON_ERROR);
            $target=null;foreach($targets as $key)if(!isset($done[$key])){$target=$key;break;}
            $attempts=(int)$row['attempts'];$due=time();$error=null;
            if($target!==null){
                try{
                    $handled=$platform->deliverForumEvent($target,$row['event_name'],json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR)+['event_id'=>$row['id'],'occurred_at'=>(int)$row['created_at']]);
                    if($this->db->connection()->inTransaction())throw new \LogicException('Subscriber left an open transaction');
                    $done[$target]=$handled?'delivered':'skipped';$stats[$handled?'delivered':'skipped']++;$attempts=0;
                }catch(\Throwable $e){
                    // Never acknowledge an event inside a subscriber's abandoned transaction.
                    $pdo=$this->db->connection();
                    if($pdo->inTransaction()){try{$pdo->rollBack();}catch(\Throwable){$this->db->disconnect();throw $e;}}
                    // Do not store arbitrary exception text or payloads containing plugin secrets.
                    $attempts++;$error=substr('Subscriber failed: '.$target,0,255);
                    if($attempts>=8){$done[$target]='dead';$stats['dead']++;$attempts=0;}
                    else{$stats['retried']++;$due+=min(3600,15*(2**($attempts-1)));}
                }
            }
            $state=count($done)===count($targets)?(in_array('dead',$done,true)?'dead':'done'):'pending';
            $u=$this->db->connection()->prepare('UPDATE ce_forum_extension_events SET progress=?,state=?,attempts=?,due_at=?,last_error=?,lease_token=NULL,lease_until=0 WHERE id=? AND lease_token=?');
            $u->execute([json_encode($done,JSON_THROW_ON_ERROR),$state,$attempts,$due,$error,$row['id'],$row['lease_token']]);
            if($u->rowCount()===1&&$state!=='pending')$stats['completed']++;
        }
        return $stats;
    }

    public function pending(): bool
    {
        $s=$this->db->connection()->prepare("SELECT id FROM ce_forum_extension_events WHERE state='pending' AND due_at<=? AND lease_until<=? LIMIT 1");$now=time();$s->execute([$now,$now]);return $s->fetchColumn()!==false;
    }
    public function diagnostics(): array
    {
        $counts=['pending'=>0,'done'=>0,'dead'=>0];
        foreach($this->db->connection()->query('SELECT state,COUNT(*) AS total FROM ce_forum_extension_events GROUP BY state')->fetchAll(\PDO::FETCH_ASSOC) as $r)$counts[$r['state']]=(int)$r['total'];
        return $counts;
    }
    public function retry(string $id): bool
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid forum event ID');
        return $this->db->transaction(function()use($id){
            $pdo=$this->db->connection();$s=$pdo->prepare("SELECT progress FROM ce_forum_extension_events WHERE id=? AND state='dead'");$s->execute([$id]);$raw=$s->fetchColumn();$s->closeCursor();if($raw===false)return false;
            $progress=array_filter(json_decode($raw,true,32,JSON_THROW_ON_ERROR),static fn($state)=>$state!=='dead');
            $u=$pdo->prepare("UPDATE ce_forum_extension_events SET state='pending',progress=?,due_at=?,attempts=0,last_error=NULL WHERE id=? AND state='dead'");$u->execute([json_encode($progress,JSON_THROW_ON_ERROR),time(),$id]);return $u->rowCount()===1;
        });
    }
    public function deadLetters(): array
    {
        return $this->db->connection()->query("SELECT id,event_name,created_at,progress,last_error FROM ce_forum_extension_events WHERE state='dead' ORDER BY created_at DESC,id LIMIT 100")->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function cleanup(): int
    {
        $pdo=$this->db->connection();$cut=time()-30*86400;
        $s=$pdo->prepare("SELECT id FROM ce_forum_extension_events WHERE state IN ('done','dead') AND created_at<? ORDER BY created_at,id LIMIT 200");$s->execute([$cut]);$ids=$s->fetchAll(\PDO::FETCH_COLUMN);$s->closeCursor();
        if(!$ids)return 0;
        $u=$pdo->prepare("DELETE FROM ce_forum_extension_events WHERE state IN ('done','dead') AND created_at<? AND id IN (".implode(',',array_fill(0,count($ids),'?')).')');$u->execute([$cut,...$ids]);return $u->rowCount();
    }
}
