<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;

/** First-party, anonymous, server-verified forum activity events.
 * Only event kind and timestamp are persisted: never actor IDs, content, URLs,
 * IP addresses, search phrases, private conversation identifiers or cookies.
 */
final readonly class ForumAnalyticsEvents
{
    public const AUDIT_EVENTS = [
        'forum.thread.create'=>'forum_thread_created',
        'forum.post.reply'=>'forum_post_published',
        'forum.post.edit'=>'forum_post_edited',
        'forum.member.register'=>'forum_registration_completed',
    ];
    public const READ_EVENTS = ['forum_search_performed','forum_attachment_downloaded'];
    public function __construct(private DatabaseManager $db) {}

    /** Called by ForumTransaction::audit inside the same SQL transaction as the mutation. */
    public function recordAudit(string $auditEvent, array $context): void
    {
        $name=self::AUDIT_EVENTS[$auditEvent]??null;
        if ($auditEvent==='forum.reaction.set') {
            $name=match($context['change']??null){
                'added'=>'forum_reaction_added',
                'removed'=>'forum_reaction_removed',
                'changed'=>'forum_reaction_changed',
                default=>null,
            };
        }
        if ($name===null) return;
        $pdo=$this->db->connection();
        if (!$pdo->inTransaction()) throw new \LogicException('Forum analytics mutation must be transactional');
        $this->insert($name);
    }

    /** Successful, explicitly permitted read operations only; isolate telemetry errors. */
    public function recordRead(string $name): void
    {
        if (!in_array($name,self::READ_EVENTS,true)) throw new \InvalidArgumentException('Unsupported read analytics event');
        // DatabaseManager::transaction rolls back even on engines that mark a
        // statement error as transaction-aborting. Do not fail a user request.
        try { $this->db->transaction(function()use($name){$this->insert($name);}); }
        catch (\Throwable) { /* Telemetry is non-critical; never expose a query or user data. */ }
    }

    /** A signed render receipt can contribute at most one anonymous page view. */
    public function recordPage(string $name,string $receiptId): bool
    {
        if (!in_array($name,ForumNativeAnalytics::PAGE_EVENTS,true) || !preg_match('/^[a-f0-9]{32}$/D',$receiptId))
            throw new \InvalidArgumentException('Invalid native page view');
        try {
            return $this->db->transaction(fn():bool=>$this->insert($name,$receiptId));
        } catch (\PDOException $e) {
            $sqlstate=(string)($e->errorInfo[0]??$e->getCode());
            $native=(int)($e->errorInfo[1]??0);
            if ($sqlstate==='23505' || ($sqlstate==='23000'&&in_array($native,[19,1062,1555,2067],true)))return false;
            throw $e;
        }
    }

    private function insert(string $name,?string $receiptId=null): bool
    {
        $pdo=$this->db->connection();
        $s=$pdo->query('SELECT payload FROM ce_forum_community_settings WHERE id=1');
        $raw=$s->fetchColumn();$s->closeCursor();
        if (!is_string($raw)) return false;
        $settings=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($settings)||($settings['analytics_enabled']??false)!==true) return false;
        $stmt=$pdo->prepare('INSERT INTO ce_forum_analytics_events(id,event_name,created_at) VALUES (?,?,?)');
        $stmt->execute([$receiptId??bin2hex(random_bytes(16)),$name,time()]);
        return true;
    }

    /** Internal metrics endpoint for operators / future aggregation; no personal data. */
    public function counts(int $from,int $until): array
    {
        if ($from<0||$until<=$from||$until>$from+366*86400) throw new \InvalidArgumentException('Invalid analytics interval');
        $stmt=$this->db->connection()->prepare('SELECT event_name, COUNT(*) AS total FROM ce_forum_analytics_events WHERE created_at>=? AND created_at<? GROUP BY event_name ORDER BY event_name');
        $stmt->execute([$from,$until]);return array_map(static fn(array $r)=>['event_name'=>$r['event_name'],'total'=>(int)$r['total']],$stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
    /** Aggregate at the database, not in the request process. No raw event rows leave SQL. */
    public function report(int $days,?int $now=null): array
    {
        if (!in_array($days,[7,30,90],true))throw new \InvalidArgumentException('Invalid analytics period');
        $now=$now??time();$end=$now+1;$start=intdiv($now,86400)*86400-($days-1)*86400;
        $stmt=$this->db->connection()->prepare('SELECT event_name,created_at-(created_at % 86400) AS day,COUNT(*) AS total FROM ce_forum_analytics_events WHERE created_at>=? AND created_at<? GROUP BY event_name,created_at-(created_at % 86400) ORDER BY day,event_name');
        $stmt->execute([$start,$end]);
        $totals=[];$series=[];
        for($i=0;$i<$days;$i++){$day=$start+$i*86400;$series[$day]=['day'=>gmdate('Y-m-d',$day),'views'=>0,'actions'=>0];}
        foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row){
            $name=(string)$row['event_name'];$n=(int)$row['total'];$day=(int)$row['day'];
            if(!isset($series[$day]))continue;
            $totals[$name]=($totals[$name]??0)+$n;
            $field=in_array($name,ForumNativeAnalytics::PAGE_EVENTS,true)?'views':'actions';
            $series[$day][$field]+=$n;
        }
        ksort($totals,SORT_STRING);
        return ['days'=>$days,'timezone'=>'UTC','from'=>gmdate('Y-m-d',$start),'until'=>gmdate('Y-m-d',$now),'totals'=>$totals,
            'page_views'=>array_sum(array_column($series,'views')),'actions'=>array_sum(array_column($series,'actions')),
            'series'=>array_values($series),'retention_days'=>90];
    }

    /** Bounded retention batches, suitable for a low-priority periodic worker. */
    public function cleanup(int $now): int
    {
        $cutoff=$now-90*86400;
        $stmt=$this->db->connection()->prepare('SELECT id FROM ce_forum_analytics_events WHERE created_at<? ORDER BY created_at,id LIMIT 250');
        $stmt->execute([$cutoff]);$ids=$stmt->fetchAll(\PDO::FETCH_COLUMN);$stmt->closeCursor();
        if (!$ids) return 0;
        $stmt=$this->db->connection()->prepare('DELETE FROM ce_forum_analytics_events WHERE created_at<? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
        $stmt->execute([$cutoff,...$ids]);return $stmt->rowCount();
    }
}
