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
        try { \CajeerEngine\Observability\Operation::measure('analytics','native.record',fn()=> $this->db->transaction(fn()=> $this->insert($name)),$this->db->config()->rootPath(),static fn($r)=>$r?'success':'skipped'); }
        catch (\Throwable) { /* Telemetry is non-critical; never expose a query or user data. */ }
    }

    /** A signed render receipt can contribute at most one anonymous page view. */
    public function recordPage(string $name,string $receiptId): bool
    {
        if (!in_array($name,ForumNativeAnalytics::PAGE_EVENTS,true) || !preg_match('/^[a-f0-9]{32}$/D',$receiptId))
            throw new \InvalidArgumentException('Invalid native page view');
        $started=hrtime(true);$outcome='failure';
        try {
            $result=$this->db->transaction(fn():bool=>$this->insert($name,$receiptId));$outcome=$result?'success':'skipped';return $result;
        } catch (\PDOException $e) {
            $sqlstate=(string)($e->errorInfo[0]??$e->getCode());
            $native=(int)($e->errorInfo[1]??0);
            if ($sqlstate==='23505' || ($sqlstate==='23000'&&in_array($native,[19,1062,1555,2067],true))){$outcome='skipped';return false;}
            throw $e;
        }finally{\CajeerEngine\Observability\Operation::record('analytics','native.record',$outcome,$started,$this->db->config()->rootPath());}
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
    /** Merged durable daily rollups + unapplied raw events in a consistent snapshot. */
    public function report(int $days,?int $now=null): array
    {
        return (new ForumAnalyticsRollup($this->db))->report($days,$now);
    }

    /** Backward-compatible bounded raw cleanup; never delete unaggregated data. */
    public function cleanup(int $now): int
    {
        return (new ForumAnalyticsRollup($this->db))->cleanup($now)['raw_deleted'];
    }

}
