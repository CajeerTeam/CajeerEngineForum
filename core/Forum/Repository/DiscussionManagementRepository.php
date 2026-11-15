<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class DiscussionManagementRepository extends Repository
{
    public function moveThread(string $id,string $board): void
    { $this->query('UPDATE ce_forum_threads SET board_id=? WHERE id=?',[$board,$id]); }
    public function selectedPosts(string $thread,array $ids): array
    { return $this->rows('SELECT id,author_id,ordinal,created_at FROM ce_forum_live_posts WHERE thread_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY ordinal',[$thread,...$ids]); }
    public function appendAll(string $source,string $target,int $offset): void
    {
        $count=(int)$this->query('SELECT MAX(ordinal) FROM ce_forum_posts WHERE thread_id=?',[$source])->fetchColumn();
        if ($count<1) throw new \CajeerEngine\Forum\ForumException('Исходная тема пуста.',409);
        $base=(new PostRepository($this->db))->reserveReadSequence($target,$count)-$count;
        // Assign before ordinal: MySQL evaluates single-table assignments left to right.
        $this->query('UPDATE ce_forum_posts SET read_sequence=ordinal+?,thread_id=?,ordinal=ordinal+? WHERE thread_id=?',[$base,$target,$offset,$source]);
    }
    public function movePost(string $id,string $source,string $target,int $ordinal): void
    { $sequence=(new PostRepository($this->db))->reserveReadSequence($target); if ($this->query('UPDATE ce_forum_posts SET thread_id=?,ordinal=?,read_sequence=? WHERE id=? AND thread_id=?',[$target,$ordinal,$sequence,$id,$source])->rowCount()!==1) throw new \CajeerEngine\Forum\ForumException('Сообщение перемещено. Повторите запрос.',409); }
    public function setFirst(string $id,array $post): void
    { $this->query('UPDATE ce_forum_threads SET author_id=?,created_at=? WHERE id=?',[$post['author_id'],$post['created_at'],$id]); }
    public function tags(string $id): array
    { return array_column($this->rows('SELECT tag FROM ce_forum_thread_tags WHERE thread_id=? ORDER BY tag',[$id]),'tag'); }
    public function metadata(string $id,string $prefix,array $tags): void
    {
        $this->query('UPDATE ce_forum_threads SET prefix=? WHERE id=?',[$prefix,$id]);
        $this->query('DELETE FROM ce_forum_thread_tags WHERE thread_id=?',[$id]);
        foreach ($tags as $tag) $this->query('INSERT INTO ce_forum_thread_tags(thread_id,tag) VALUES(?,?)',[$id,$tag]);
    }
    public function mergeWatches(string $source,string $target): void
    {
        // Both threads are locked, so concurrent subscription changes cannot race this insert.
        $this->query('INSERT INTO ce_forum_watches(thread_id,user_id,mode) SELECT ?,s.user_id,s.mode FROM ce_forum_watches s WHERE s.thread_id=? AND NOT EXISTS (SELECT 1 FROM ce_forum_watches d WHERE d.thread_id=? AND d.user_id=s.user_id)',[$target,$source,$target]);
    }
    public function redirect(string $source,string $board,string $target): void
    {
        $this->query('UPDATE ce_forum_thread_redirects SET target_id=? WHERE target_id=?',[$target,$source]);
        $this->query('INSERT INTO ce_forum_thread_redirects(source_id,source_board_id,target_id) VALUES(?,?,?)',[$source,$board,$target]);
    }
    public function findRedirect(string $id): ?array
    { return $this->row('SELECT source_board_id,target_id FROM ce_forum_thread_redirects WHERE source_id=?',[$id]); }
}
