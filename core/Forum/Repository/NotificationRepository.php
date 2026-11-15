<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

use CajeerEngine\Support\Uuid;

/** Rows are created in the publication transaction and promoted by the worker. */
final readonly class NotificationRepository extends Repository
{
    public const KINDS=['reply','mention','quote','reaction','moderation','accepted'];
    private const FEED="(SELECT event_id,recipient_id,actor_id,post_id,kind,moderation_action,subscription_kind,state,is_read,created_at FROM ce_forum_notifications UNION ALL SELECT event_id,recipient_id,actor_id,post_id,'accepted' AS kind,NULL AS moderation_action,NULL AS subscription_kind,state,is_read,created_at FROM ce_forum_answer_notifications)";

    public function enqueue(string $kind,string $actor,string $post,array $recipients,?string $action=null,?string $subscription=null): void
    {
        if (($subscription!==null && ($subscription!=='board'||$kind!=='reply')) || !$this->db->connection()->inTransaction() || (!in_array($kind,self::KINDS,true)||$kind==='accepted') || ($kind==='moderation'?!in_array($action,['lock','unlock','pin','unpin'],true):$action!==null)) throw new \LogicException('Invalid notification event or missing transaction');
        $event=Uuid::v4();
        $uuid=$this->db->driver()==='pgsql'?'CAST(? AS uuid)':'?';
        foreach (array_unique($recipients) as $recipient) {
            if ($recipient==='' || $recipient===$actor) continue;
            $this->query('INSERT INTO ce_forum_notifications(event_id,recipient_id,actor_id,post_id,kind,moderation_action,subscription_kind) SELECT '.$uuid.',u.id,'.$uuid.','.$uuid.',?,?,? FROM ce_users u WHERE u.id=? AND u.status=\'active\' AND COALESCE((SELECT pref.'.$kind.' FROM ce_forum_notification_preferences pref WHERE pref.user_id=u.id),1)=1',[$event,$actor,$post,$kind,$action,$subscription,$recipient]);
        }
    }
    public function reply(string $actor,string $post,string $thread): void
    {
        if (!$this->db->connection()->inTransaction()) throw new \LogicException('Notification event requires a transaction');
        // One set-based insert, independent of the number of subscribers.
        $uuid=$this->db->driver()==='pgsql'?'CAST(? AS uuid)':'?';
        $this->query("INSERT INTO ce_forum_notifications(event_id,recipient_id,actor_id,post_id,kind,subscription_kind) SELECT $uuid,u.id,$uuid,$uuid,'reply',CASE WHEN t.author_id=u.id THEN NULL ELSE 'thread' END FROM (SELECT author_id AS user_id FROM ce_forum_threads WHERE id=? UNION SELECT user_id FROM ce_forum_watches WHERE thread_id=? AND mode='internal') candidates JOIN ce_forum_threads t ON t.id=? JOIN ce_users u ON u.id=candidates.user_id LEFT JOIN ce_forum_notification_preferences pref ON pref.user_id=u.id WHERE u.status='active' AND u.id<>? AND COALESCE(pref.reply,1)=1",[Uuid::v4(),$actor,$post,$thread,$thread,$thread,$actor]);
    }
    public function subscriptionEligible(string $user,string $post,string $kind): bool
    {
        $sql=$kind==='thread'?"EXISTS (SELECT 1 FROM ce_forum_watches w WHERE w.thread_id=t.id AND w.user_id=? AND w.mode='internal')":"p.ordinal=1 AND EXISTS (SELECT 1 FROM ce_forum_board_watches b WHERE b.board_id=t.board_id AND b.user_id=? AND b.mode='internal')";
        return $this->row('SELECT p.id FROM ce_forum_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE p.id=? AND '.$sql,[$post,$user])!==null;
    }
    public function quotedAuthor(string $id,string $thread): ?string
    { return $this->row('SELECT author_id FROM ce_forum_posts WHERE id=? AND thread_id=?',[$id,$thread])['author_id']??null; }
    public function firstPost(string $thread): ?string { return $this->row('SELECT id FROM ce_forum_posts WHERE thread_id=? ORDER BY ordinal LIMIT 1',[$thread])['id']??null; }
    public function postAuthor(string $id): ?string
    { return $this->row('SELECT author_id FROM ce_forum_posts WHERE id=?',[$id])['author_id']??null; }
    public function removeReaction(string $actor,string $post): void
    { $this->query("DELETE FROM ce_forum_notifications WHERE actor_id=? AND post_id=? AND kind='reaction'",[$actor,$post]); }
    public function accepted(string $actor,string $post,string $recipient): void
    {
        if(!$this->db->connection()->inTransaction())throw new \LogicException('Acceptance event requires a transaction');
        if($actor===$recipient)return;
        $uuid=$this->db->driver()==='pgsql'?'CAST(? AS uuid)':'?';
        $this->query('INSERT INTO ce_forum_answer_notifications(event_id,recipient_id,actor_id,post_id) SELECT '.$uuid.',u.id,'.$uuid.','.$uuid." FROM ce_users u WHERE u.id=? AND u.status='active' AND COALESCE((SELECT pref.accepted FROM ce_forum_notification_preferences pref WHERE pref.user_id=u.id),1)=1",[Uuid::v4(),$actor,$post,$recipient]);
    }
    public function removeAccepted(string $post): void { $this->query('DELETE FROM ce_forum_answer_notifications WHERE post_id=?',[$post]); }
    public function preferences(string $user): array
    {
        $row=$this->row('SELECT reply,mention,quote,reaction,moderation,accepted FROM ce_forum_notification_preferences WHERE user_id=?',[$user])??array_fill_keys(self::KINDS,1);
        return array_map(static fn($v)=>(int)$v===1,$row);
    }
    public function savePreferences(string $user,array $values): void
    {
        $suffix=$this->db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE reply=VALUES(reply),mention=VALUES(mention),quote=VALUES(quote),reaction=VALUES(reaction),moderation=VALUES(moderation),accepted=VALUES(accepted)':' ON CONFLICT(user_id) DO UPDATE SET reply=excluded.reply,mention=excluded.mention,quote=excluded.quote,reaction=excluded.reaction,moderation=excluded.moderation,accepted=excluded.accepted';
        $this->query('INSERT INTO ce_forum_notification_preferences(user_id,reply,mention,quote,reaction,moderation,accepted) VALUES(?,?,?,?,?,?,?)'.$suffix,[$user,...array_map(static fn($k)=>(int)$values[$k],self::KINDS)]);
    }
    public function pending(int $limit): array
    { return $this->rows('SELECT event_id,recipient_id,kind FROM '.self::FEED.' n WHERE state=0 ORDER BY created_at,event_id,recipient_id LIMIT '.max(1,min(1000,$limit))); }
    public function claim(string $event,string $user,string $kind): ?array
    {
        if (!$this->db->connection()->inTransaction()) throw new \LogicException('Notification claim requires transaction');
        
        $table=$kind==='accepted'?'ce_forum_answer_notifications':'ce_forum_notifications';
        if ($this->db->driver()==='sqlite') $this->query('UPDATE '.$table.' SET state=state WHERE event_id=? AND recipient_id=? AND state=0',[$event,$user]);
        $row=$this->row('SELECT * FROM '.$table.' WHERE event_id=? AND recipient_id=? AND state=0'.($this->db->driver()==='sqlite'?'':' FOR UPDATE'),[$event,$user]);
        return $row===null?null:$row+['kind'=>'accepted','subscription_kind'=>null];
    }
    public function target(string $post): ?array
    { return $this->row('SELECT t.board_id FROM ce_forum_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE p.id=?',[$post]); }
    public function finish(string $event,string $user,bool $deliver,string $kind): void
    { $table=$kind==='accepted'?'ce_forum_answer_notifications':'ce_forum_notifications'; $this->query(($deliver?'UPDATE '.$table.' SET state=1':'DELETE FROM '.$table).' WHERE event_id=? AND recipient_id=? AND state=0',[$event,$user]); }
    private function visible(array $allowed): string
    { return ' FROM '.self::FEED.' n JOIN ce_forum_posts p ON p.id=n.post_id JOIN ce_forum_threads t ON t.id=p.thread_id WHERE n.recipient_id=? AND n.state=1 AND '.$this->boardFilter('t.board_id',$allowed); }
    public function inbox(string $user,array $allowed,int $offset,?string $id=null): array
    {
        $params=[$user];if($id!==null)$params[]=$id;
        $rows=$this->rows('SELECT n.event_id AS id,n.kind,n.moderation_action,n.subscription_kind,n.is_read,n.created_at,n.actor_id,(SELECT u.name FROM ce_users u WHERE u.id=n.actor_id) AS actor_name,n.post_id,t.id AS thread_id,t.title,(SELECT COUNT(*) FROM ce_forum_posts p2 WHERE p2.thread_id=p.thread_id AND p2.ordinal<p.ordinal) AS preceding'.$this->visible($allowed).($id===null?'':' AND n.event_id=?').' ORDER BY n.created_at DESC,n.event_id DESC LIMIT 25 OFFSET '.$offset,$params);
        foreach($rows as &$r){$r['is_read']=(int)$r['is_read']===1;$r['page']=1+intdiv((int)$r['preceding'],25);unset($r['preceding']);}unset($r);
        return $rows;
    }
    public function countUnread(string $user,array $allowed): int
    { return (int)$this->query('SELECT COUNT(*)'.$this->visible($allowed).' AND n.is_read=0',[$user])->fetchColumn(); }
    public function mark(string $user,array $allowed,?string $id): bool
    {
        $params=[$user];$where=$this->visible($allowed);
        if($id!==null){$where.=' AND n.event_id=?';$params[]=$id;}
        // Select in the same snapshot; do not expose foreign or hidden notification IDs.
        if($id!==null && $this->row('SELECT n.event_id'.$where,$params)===null)return false;
        // Correlated EXISTS is portable, including MySQL's target-table restriction.
        foreach(['ce_forum_notifications','ce_forum_answer_notifications'] as $table){
            $sql='UPDATE '.$table.' SET is_read=1 WHERE recipient_id=? AND state=1 AND is_read=0 AND EXISTS (SELECT 1 FROM ce_forum_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE p.id='.$table.'.post_id AND '.$this->boardFilter('t.board_id',$allowed).')';
            $this->query($sql.($id===null?'':' AND event_id=?'),$params);
        }
        return true;
    }
}
