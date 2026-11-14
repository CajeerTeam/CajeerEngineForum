<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class PostRepository extends Repository
{
    public function inThread(string $id, int $offset,string $viewer=''): array
    {
        $rows=$this->rows("SELECT p.*,u.name AS author,u.status AS author_status, m.display_name,m.signature,COALESCE(pref.profile_visibility,'public') AS profile_visibility,CASE WHEN img.user_id IS NULL THEN 0 ELSE 1 END AS has_avatar,(SELECT COUNT(*) FROM ce_forum_reactions a WHERE a.post_id=p.id AND a.reaction_type='like') AS likes FROM ce_forum_posts p LEFT JOIN ce_users u ON u.id=p.author_id LEFT JOIN ce_forum_member_profiles m ON m.user_id=u.id LEFT JOIN ce_forum_member_preferences pref ON pref.user_id=u.id LEFT JOIN ce_forum_profile_images img ON img.user_id=u.id AND img.kind='avatar' WHERE p.thread_id=? ORDER BY p.ordinal LIMIT 25 OFFSET ".$offset,[$id]);
        $show=$viewer===''|| (int)(new ProfileRepository($this->db))->preferences($viewer)['show_signatures']===1;
        $counts=[];$mine=[];$ignored=[];
        if($rows){
            $ids=array_column($rows,'id');$in=implode(',',array_fill(0,count($ids),'?'));
            foreach($this->rows("SELECT post_id,reaction_type,COUNT(*) AS amount FROM ce_forum_reactions WHERE post_id IN ($in) GROUP BY post_id,reaction_type",$ids) as $r)$counts[$r['post_id']][$r['reaction_type']]=(int)$r['amount'];
            if($viewer!==''){
                foreach($this->rows("SELECT post_id,reaction_type FROM ce_forum_reactions WHERE user_id=? AND post_id IN ($in)",[$viewer,...$ids]) as $r)$mine[$r['post_id']]=$r['reaction_type'];
                $ignored=array_fill_keys(array_column($this->rows("SELECT target_id FROM ce_forum_member_relations WHERE user_id=? AND kind='ignore'",[$viewer]),'target_id'),true);
            }
        }
        foreach($rows as &$row){
            $row['reactions']=$counts[$row['id']]??[];$row['my_reaction']=$mine[$row['id']]??null;
            $row['ignored_author']=isset($ignored[$row['author_id']??'']);
            $visible=$row['author_status']==='active' && \CajeerEngine\Forum\Service\MemberProfileService::visible($row['profile_visibility'],$viewer,(string)$row['author_id']);
            $row['author_profile_visible']=$visible;
            if($visible && $row['display_name'])$row['author']=$row['display_name'];
            $row['signature']=$visible&&$show?($row['signature']??''):'';
            $row['avatar_url']=$visible&&(int)$row['has_avatar']===1?'/api/v1/forum/members/'.$row['author_id'].'/images/avatar':null;
            unset($row['author_status'],$row['display_name'],$row['profile_visibility'],$row['has_avatar']);
        }unset($row);
        return $rows;
    }
    public function findForWrite(string $id): ?array
    {
        if (!$this->db->connection()->inTransaction()) throw new \LogicException('Forum post lock requires a transaction');
        return $this->row('SELECT * FROM ce_forum_posts WHERE id=?'.($this->db->driver() === 'sqlite' ? '' : ' FOR UPDATE'), [$id]);
    }
    public function firstId(string $thread): ?string { return $this->row('SELECT id FROM ce_forum_posts WHERE thread_id=? ORDER BY ordinal LIMIT 1',[$thread])['id']??null; }
    public function nextOrdinal(string $thread): int
    {
        // The caller holds the thread lock. A locking read also avoids stale
        // MySQL repeatable-read snapshots for callers using nested transactions.
        $sql = 'SELECT ordinal FROM ce_forum_posts WHERE thread_id=? ORDER BY ordinal DESC LIMIT 1';
        if ($this->db->driver() !== 'sqlite') $sql .= ' FOR UPDATE';
        return 1 + (int) $this->query($sql, [$thread])->fetchColumn();
    }
    /** Reserve an append range under the caller's thread lock. Never lower the counter. */
    public function reserveReadSequence(string $thread, int $count=1): int
    {
        if (!$this->db->connection()->inTransaction() || $count<1) throw new \LogicException('Read sequence requires a locked thread and positive count');
        $this->query('UPDATE ce_forum_threads SET read_sequence=read_sequence+? WHERE id=?',[$count,$thread]);
        return (int)$this->query('SELECT read_sequence FROM ce_forum_threads WHERE id=?',[$thread])->fetchColumn();
    }
    public function insert(string $id, string $thread, string $actor, string $body, int $ordinal): void
    {
        $sequence=$this->reserveReadSequence($thread);
        $this->query('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)',[$id,$thread,$actor,$body,$ordinal,$sequence]);
    }
    public function edit(string $id, string $body): void { $this->query('UPDATE ce_forum_posts SET body=?,edited_at=CURRENT_TIMESTAMP WHERE id=?', [$body,$id]); }
}
