<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

/** Physical rows are deliberately accessed only by lifecycle operations. */
final readonly class RecycleBinRepository extends Repository
{
    public function lockTarget(string $kind,string $id): ?array
    {
        if($kind==='thread') return $this->lock('ce_forum_threads','id=?',[$id]);
        $thread=$this->lock('ce_forum_threads','id=(SELECT thread_id FROM ce_forum_posts WHERE id=?)',[$id]);
        if(!$thread)return null;
        $post=$this->lock('ce_forum_posts','id=?',[$id]);
        if(!$post)return null;
        if($post['thread_id']!==$thread['id'])throw new \CajeerEngine\Forum\ForumException('Сообщение перемещено. Повторите запрос.',409);
        return $post+['board_id'=>$thread['board_id'],'parent_deleted'=>$thread['deleted_at']!==null];
    }
    public function target(string $kind,string $id): ?array
    {
        if($kind==='thread')return $this->row('SELECT id,board_id,title,deleted_at,deleted_by,delete_reason,deletion_id FROM ce_forum_threads WHERE id=? AND deleted_at IS NOT NULL',[$id]);
        return $this->row('SELECT p.id,p.thread_id,t.board_id,t.title,p.deleted_at,p.deleted_by,p.delete_reason,p.deletion_id FROM ce_forum_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE p.id=? AND p.deleted_at IS NOT NULL AND t.deleted_at IS NULL',[$id]);
    }
    public function contents(string $kind,string $id,int $offset): array
    {
        return $this->rows('SELECT id,author_id,ordinal,body,body_format,revision_number,deleted_at FROM ce_forum_posts WHERE '.($kind==='thread'?'thread_id=?':'id=?').' ORDER BY ordinal LIMIT 25 OFFSET '.$offset,[$id]);
    }
    public function first(string $thread): ?string
    {return $this->row('SELECT id FROM ce_forum_posts WHERE thread_id=? ORDER BY ordinal LIMIT 1',[$thread])['id']??null;}
    private function table(string $kind): string
    {return match($kind){'thread'=>'ce_forum_threads','post'=>'ce_forum_posts',default=>throw new \LogicException('Invalid recycle kind')};}
    public function hide(string $kind,string $id,string $actor,string $reason,int $now,string $generation): void
    {$this->query('UPDATE '.$this->table($kind).' SET deleted_at=?,deleted_by=?,delete_reason=?,deletion_id=? WHERE id=? AND deleted_at IS NULL',[$now,$actor,$reason,$generation,$id]);}
    public function restore(string $kind,string $id): void
    {$this->query('UPDATE '.$this->table($kind).' SET deleted_at=NULL,deleted_by=NULL,delete_reason=NULL,deletion_id=NULL WHERE id=? AND deleted_at IS NOT NULL',[$id]);}
    public function hasOpenReports(string $kind,string $id): bool
    {
        $filter=$kind==='thread'?'p.thread_id=?':'p.id=?';
        return $this->row("SELECT r.id FROM ce_forum_reports r JOIN ce_forum_posts p ON p.id=r.post_id WHERE r.status='open' AND $filter LIMIT 1",[$id])!==null;
    }
    public function purge(string $kind,string $id): bool
    {return $this->query('DELETE FROM '.$this->table($kind).' WHERE id=? AND deleted_at IS NOT NULL',[$id])->rowCount()===1;}
    /** Cancel only pending deliveries; previously delivered notifications remain hidden by the live views. */
    public function suppress(string $kind,string $id): void
    {
        $filter=$kind==='thread'?'post_id IN (SELECT id FROM ce_forum_posts WHERE thread_id=?)':'post_id=?';
        foreach(['ce_forum_notifications','ce_forum_answer_notifications'] as $table)
            $this->query("DELETE FROM $table WHERE state=0 AND $filter",[$id]);
        $this->query("UPDATE ce_forum_mail_delivery SET state='cancelled',lease_token=NULL,lease_until=0 WHERE state='pending' AND $filter",[$id]);
    }
    public function listing(array $boards,int $offset,string $kind): array
    {
        $threads="SELECT 'thread' AS kind,t.id,t.id AS thread_id,t.board_id,t.title,t.deleted_at,t.deleted_by,t.delete_reason,t.deletion_id FROM ce_forum_threads t WHERE t.deleted_at IS NOT NULL AND ".$this->boardFilter('t.board_id',$boards);
        $posts="SELECT 'post' AS kind,p.id,p.thread_id,t.board_id,t.title,p.deleted_at,p.deleted_by,p.delete_reason,p.deletion_id FROM ce_forum_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE p.deleted_at IS NOT NULL AND t.deleted_at IS NULL AND ".$this->boardFilter('t.board_id',$boards);
        $sql=match($kind){'thread'=>$threads,'post'=>$posts,default=>$threads.' UNION ALL '.$posts};
        return $this->rows('SELECT * FROM ('.$sql.') trash ORDER BY deleted_at DESC,id LIMIT 25 OFFSET '.$offset);
    }
    /** Exclude open cases before LIMIT so protected records cannot starve cleanup. */
    public function expired(int $cutoff): array
    {
        return $this->rows("SELECT * FROM (SELECT 'thread' AS kind,t.id,t.deleted_at FROM ce_forum_threads t WHERE t.deleted_at<=? AND NOT EXISTS (SELECT 1 FROM ce_forum_reports r JOIN ce_forum_posts p ON p.id=r.post_id WHERE p.thread_id=t.id AND r.status='open') UNION ALL SELECT 'post' AS kind,p.id,p.deleted_at FROM ce_forum_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE p.deleted_at<=? AND t.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM ce_forum_reports r WHERE r.post_id=p.id AND r.status='open')) trash ORDER BY deleted_at,id LIMIT 100",[$cutoff,$cutoff]);
    }
}
