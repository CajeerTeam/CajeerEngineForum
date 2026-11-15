<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class PostRepository extends Repository
{
    public function inThread(string $id, int $offset,string $viewer=''): array
    {
        $rows=$this->rows("SELECT p.*,u.name AS author,u.status AS author_status, m.display_name,m.signature,COALESCE(pref.profile_visibility,'public') AS profile_visibility,CASE WHEN img.user_id IS NULL THEN 0 ELSE 1 END AS has_avatar FROM (SELECT * FROM ce_forum_live_posts WHERE thread_id=? ORDER BY ordinal LIMIT 25 OFFSET ".$offset.") p LEFT JOIN ce_users u ON u.id=p.author_id LEFT JOIN ce_forum_member_profiles m ON m.user_id=u.id LEFT JOIN ce_forum_member_preferences pref ON pref.user_id=u.id LEFT JOIN ce_forum_profile_images img ON img.user_id=u.id AND img.kind='avatar' ORDER BY p.ordinal",[$id]);
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
        
        $quotes=[];
        foreach($rows as &$row){
            $content=\CajeerEngine\Forum\Content\ForumContentRenderer::render($row['body'],$row['body_format']);
            $row['revision_number']=(int)$row['revision_number'];$row['body_html']=$content['html'];$row['renderer_version']=$content['renderer_version'];
            foreach($content['quotes'] as $quote)$quotes[$quote]=true;
            $row['reactions']=$counts[$row['id']]??[];$row['likes']=$row['reactions']['like']??0;$row['my_reaction']=$mine[$row['id']]??null;
            $row['ignored_author']=isset($ignored[$row['author_id']??'']);
            $visible=$row['author_status']==='active' && \CajeerEngine\Forum\Service\MemberProfileService::visible($row['profile_visibility'],$viewer,(string)$row['author_id']);
            $row['author_profile_visible']=$visible;
            if($visible && $row['display_name'])$row['author']=$row['display_name'];
            $row['signature']=$visible&&$show?($row['signature']??''):'';
            $row['avatar_url']=$visible&&(int)$row['has_avatar']===1?'/api/v1/forum/members/'.$row['author_id'].'/images/avatar':null;
            unset($row['author_status'],$row['display_name'],$row['profile_visibility'],$row['has_avatar']);
        }unset($row);
        // Only current-thread references are resolved. Never look up titles or bodies elsewhere.
        if($quotes){
            $pages=[];$ids=array_keys($quotes);$in=implode(',',array_fill(0,count($ids),'?'));
            foreach($this->rows("SELECT p.id,(SELECT COUNT(*) FROM ce_forum_live_posts q WHERE q.thread_id=p.thread_id AND q.ordinal<=p.ordinal) AS position FROM ce_forum_live_posts p WHERE p.thread_id=? AND p.id IN ($in)",[$id,...$ids]) as $q)$pages[$q['id']]=1+intdiv((int)$q['position']-1,25);
            foreach($rows as &$row)$row['body_html']=preg_replace_callback('/href="#post-([a-f0-9-]{36})"/',static fn($m)=>isset($pages[$m[1]])?'href="/forum/threads/'.$id.'?page='.$pages[$m[1]].'#post-'.$m[1].'"':'', $row['body_html'])??$row['body_html'];unset($row);
        }
        return $rows;
    }
    public function findForWrite(string $id): ?array
    {
        if (!$this->db->connection()->inTransaction()) throw new \LogicException('Forum post lock requires a transaction');
        return $this->row('SELECT * FROM ce_forum_posts WHERE id=? AND deleted_at IS NULL'.($this->db->driver() === 'sqlite' ? '' : ' FOR UPDATE'), [$id]);
    }
    public function countInThread(string $id): int { return (int)$this->query('SELECT COUNT(*) FROM ce_forum_live_posts WHERE thread_id=?',[$id])->fetchColumn(); }
    public function firstId(string $thread): ?string { return $this->row('SELECT id FROM ce_forum_live_posts WHERE thread_id=? ORDER BY ordinal LIMIT 1',[$thread])['id']??null; }
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
        return (int)$this->query('SELECT read_sequence FROM ce_forum_live_threads WHERE id=?',[$thread])->fetchColumn();
    }
    public function insert(string $id, string $thread, string $actor, string $body, int $ordinal, string $format='plain'): void
    {
        $sequence=$this->reserveReadSequence($thread);
        $this->query('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence,body_format) VALUES(?,?,?,?,?,?,?)',[$id,$thread,$actor,$body,$ordinal,$sequence,$format]);
    }
    public function edit(string $id, string $body, ?string $format=null): void { $this->query('UPDATE ce_forum_posts SET body=?,body_format=COALESCE(?,body_format),edited_at=CURRENT_TIMESTAMP WHERE id=?', [$body,$format,$id]); }
}
