<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

/** Bounded pages of live threads, filtered by server-resolved guest ACLs. */
final readonly class ForumSitemapRepository extends Repository
{
    public function latest(array $boards): array
    {
        return $this->rows('SELECT t.id,t.title,t.updated_at FROM ce_forum_live_threads t WHERE '.$this->boardFilter('t.board_id',$boards).' AND EXISTS (SELECT 1 FROM ce_forum_live_posts p WHERE p.thread_id=t.id) ORDER BY t.updated_at DESC,t.id LIMIT 25');
    }
    public function countThreads(array $boards): int
    {
        return (int)$this->query('SELECT COUNT(*) FROM ce_forum_live_threads t WHERE '.$this->boardFilter('t.board_id',$boards).' AND EXISTS (SELECT 1 FROM ce_forum_live_posts p WHERE p.thread_id=t.id)')->fetchColumn();
    }
    public function threads(array $boards,int $offset,int $limit): array
    {
        if($offset<0 || $limit<1 || $limit>1000)throw new \InvalidArgumentException('Invalid sitemap window');
        return $this->rows('SELECT t.id FROM ce_forum_live_threads t WHERE '.$this->boardFilter('t.board_id',$boards).' AND EXISTS (SELECT 1 FROM ce_forum_live_posts p WHERE p.thread_id=t.id) ORDER BY t.id LIMIT '.$limit.' OFFSET '.$offset);
    }
}
