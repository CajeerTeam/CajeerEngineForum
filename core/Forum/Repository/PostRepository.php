<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class PostRepository extends Repository
{
    public function inThread(string $id, int $offset): array { return $this->rows('SELECT p.*,u.name AS author,(SELECT COUNT(*) FROM ce_forum_reactions a WHERE a.post_id=p.id) AS likes FROM ce_forum_posts p LEFT JOIN ce_users u ON u.id=p.author_id WHERE p.thread_id=? ORDER BY p.ordinal LIMIT 25 OFFSET '.$offset, [$id]); }
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
    public function insert(string $id, string $thread, string $actor, string $body, int $ordinal): void { $this->query('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal) VALUES(?,?,?,?,?)', [$id,$thread,$actor,$body,$ordinal]); }
    public function edit(string $id, string $body): void { $this->query('UPDATE ce_forum_posts SET body=?,edited_at=CURRENT_TIMESTAMP WHERE id=?', [$body,$id]); }
}
