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
        $recipients=array_values(array_filter(array_unique($recipients),static fn($id)=>$id!==''&&$id!==$actor));
        // Bound parameter counts across drivers. A single event ID is shared by all
        // recipients; preferences and active account status remain SQL predicates.
        foreach(array_chunk($recipients,200) as $chunk){
            $slots=implode(',',array_fill(0,count($chunk),'?'));
            $this->query('INSERT INTO ce_forum_notifications(event_id,recipient_id,actor_id,post_id,kind,moderation_action,subscription_kind) SELECT '.$uuid.',u.id,'.$uuid.','.$uuid.',?,?,? FROM ce_users u WHERE u.id IN ('.$slots.") AND u.status='active' AND COALESCE((SELECT pref.".$kind.' FROM ce_forum_notification_preferences pref WHERE pref.user_id=u.id),1)=1',[$event,$actor,$post,$kind,$action,$subscription,...$chunk]);
        }
    }
    public function reply(string $actor,string $post,string $thread): void
    {
        if (!$this->db->connection()->inTransaction()) throw new \LogicException('Notification event requires a transaction');
        // One set-based insert, independent of the number of subscribers.
        $uuid=$this->db->driver()==='pgsql'?'CAST(? AS uuid)':'?';
        $this->query("INSERT INTO ce_forum_notifications(event_id,recipient_id,actor_id,post_id,kind,subscription_kind) SELECT $uuid,u.id,$uuid,$uuid,'reply',CASE WHEN t.author_id=u.id THEN NULL ELSE 'thread' END FROM (SELECT author_id AS user_id FROM ce_forum_live_threads WHERE id=? UNION SELECT user_id FROM ce_forum_watches WHERE thread_id=? AND mode='internal') candidates JOIN ce_forum_live_threads t ON t.id=? JOIN ce_users u ON u.id=candidates.user_id LEFT JOIN ce_forum_notification_preferences pref ON pref.user_id=u.id WHERE u.status='active' AND u.id<>? AND COALESCE(pref.reply,1)=1",[Uuid::v4(),$actor,$post,$thread,$thread,$thread,$actor]);
    }
    public function subscriptionEligible(string $user,string $post,string $kind): bool
    {
        $sql=$kind==='thread'?"EXISTS (SELECT 1 FROM ce_forum_watches w WHERE w.thread_id=t.id AND w.user_id=? AND w.mode='internal')":"p.ordinal=1 AND EXISTS (SELECT 1 FROM ce_forum_board_watches b WHERE b.board_id=t.board_id AND b.user_id=? AND b.mode='internal')";
        return $this->row('SELECT p.id FROM ce_forum_live_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE p.id=? AND '.$sql,[$post,$user])!==null;
    }
    public function quotedAuthor(string $id,string $thread): ?string
    { return $this->row('SELECT author_id FROM ce_forum_live_posts WHERE id=? AND thread_id=?',[$id,$thread])['author_id']??null; }
    public function firstPost(string $thread): ?string { return $this->row('SELECT id FROM ce_forum_live_posts WHERE thread_id=? ORDER BY ordinal LIMIT 1',[$thread])['id']??null; }
    public function postAuthor(string $id): ?string
    { return $this->row('SELECT author_id FROM ce_forum_live_posts WHERE id=?',[$id])['author_id']??null; }
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
        $row=$this->row('SELECT * FROM '.$table.' WHERE event_id=? AND recipient_id=? AND state=0'.(match($this->db->driver()){'sqlite'=>'','pgsql'=>' FOR UPDATE SKIP LOCKED',default=>' FOR UPDATE'}),[$event,$user]);
        return $row===null?null:$row+['kind'=>'accepted','subscription_kind'=>null];
    }
    public function target(string $post): ?array
    { return $this->row('SELECT t.board_id FROM ce_forum_live_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE p.id=?',[$post]); }
    public function finish(string $event,string $user,bool $deliver,string $kind): void
    { $table=$kind==='accepted'?'ce_forum_answer_notifications':'ce_forum_notifications'; $this->query(($deliver?'UPDATE '.$table.' SET state=1':'DELETE FROM '.$table).' WHERE event_id=? AND recipient_id=? AND state=0',[$event,$user]); }
    /** Push recipient and delivery-state filtering into BOTH UNION branches.
     * A large global notification history must never be materialized before
     * filtering the requesting principal's inbox or unread count.
     */
    private function visible(array $allowed,bool $unread=false): string
    {
        $predicate='recipient_id=? AND state=1'.($unread?' AND is_read=0':'');
        $feed="(SELECT event_id,recipient_id,actor_id,post_id,kind,moderation_action,subscription_kind,state,is_read,created_at FROM ce_forum_notifications WHERE $predicate UNION ALL SELECT event_id,recipient_id,actor_id,post_id,'accepted' AS kind,NULL AS moderation_action,NULL AS subscription_kind,state,is_read,created_at FROM ce_forum_answer_notifications WHERE $predicate)";
        return ' FROM '.$feed.' n JOIN ce_forum_live_posts p ON p.id=n.post_id JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE (n.kind=\'moderation\' OR NOT EXISTS (SELECT 1 FROM ce_forum_user_blocks b WHERE (b.user_id=n.recipient_id AND b.blocked_id=n.actor_id) OR (b.blocked_id=n.recipient_id AND b.user_id=n.actor_id))) AND '.$this->boardFilter('t.board_id',$allowed);
    }
    public function inbox(string $user,array $allowed,int $offset,?string $id=null): array
    {
        $params=[$user,$user];if($id!==null)$params[]=$id;
        $rows=$this->rows('SELECT pgrow.*,u.name AS actor_name,(SELECT COUNT(*) FROM ce_forum_posts p2 WHERE p2.thread_id=pgrow.thread_id AND p2.deleted_at IS NULL AND p2.ordinal<pgrow.ordinal) AS preceding FROM (SELECT n.event_id AS id,n.kind,n.moderation_action,n.subscription_kind,n.is_read,n.created_at,n.actor_id,n.post_id,t.id AS thread_id,t.title,p.ordinal'.$this->visible($allowed).($id===null?'':' AND n.event_id=?').' ORDER BY n.created_at DESC,n.event_id DESC LIMIT 25 OFFSET '.$offset.') pgrow LEFT JOIN ce_users u ON u.id=pgrow.actor_id ORDER BY pgrow.created_at DESC,pgrow.id DESC',$params);
        foreach($rows as &$r){$r['is_read']=(int)$r['is_read']===1;$r['page']=1+intdiv((int)$r['preceding'],25);unset($r['preceding'],$r['ordinal']);}unset($r);
        return $rows;
    }
    public function countUnread(string $user,array $allowed): int
    { return (int)$this->query('SELECT COUNT(*)'.$this->visible($allowed,true),[$user,$user])->fetchColumn(); }
    public function mark(string $user,array $allowed,?string $id): bool
    {
        $where=$this->visible($allowed);
        $selectParams=[$user,$user];
        $updateParams=[$user];
        if($id!==null){$where.=' AND n.event_id=?';$selectParams[]=$id;$updateParams[]=$id;}
        // Select in the same snapshot; do not expose foreign or hidden notification IDs.
        if($id!==null && $this->row('SELECT n.event_id'.$where,$selectParams)===null)return false;
        // Correlated EXISTS is portable, including MySQL's target-table restriction.
        foreach(['ce_forum_notifications','ce_forum_answer_notifications'] as $table){
            $sql='UPDATE '.$table.' SET is_read=1 WHERE recipient_id=? AND state=1 AND is_read=0 AND EXISTS (SELECT 1 FROM ce_forum_live_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE p.id='.$table.'.post_id AND '.$this->boardFilter('t.board_id',$allowed).')';
            $this->query($sql.($id===null?'':' AND event_id=?'),$updateParams);
        }
        return true;
    }
}
