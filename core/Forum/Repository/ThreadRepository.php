<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class ThreadRepository extends Repository
{
    public function find(string $id): ?array { return $this->row('SELECT t.*,b.name AS board_name FROM ce_forum_threads t JOIN ce_forum_boards b ON b.id=t.board_id WHERE t.id=?', [$id]); }
    public function lockThread(string $id): ?array { return $this->lock('ce_forum_threads', 'id=?', [$id]); }
    public function lockForPost(string $post): ?array { return $this->lock('ce_forum_threads', 'id=(SELECT thread_id FROM ce_forum_posts WHERE id=?)', [$post]); }
    public function inBoard(string $id, int $offset): array { return $this->rows('SELECT t.*,u.name AS author,(SELECT COUNT(*) FROM ce_forum_posts p WHERE p.thread_id=t.id) AS posts FROM ce_forum_threads t LEFT JOIN ce_users u ON u.id=t.author_id WHERE t.board_id=? ORDER BY pinned DESC,updated_at DESC,id LIMIT 25 OFFSET '.$offset, [$id]); }
    public function latest(int $offset): array { return $this->rows('SELECT t.id,t.title,t.updated_at,t.pinned,t.locked,u.name AS author FROM ce_forum_threads t LEFT JOIN ce_users u ON u.id=t.author_id ORDER BY t.updated_at DESC,t.id LIMIT 25 OFFSET '.$offset); }
    public function byAuthor(string $id, int $offset): array { return $this->rows('SELECT id,title,updated_at FROM ce_forum_threads WHERE author_id=? ORDER BY updated_at DESC,id LIMIT 25 OFFSET '.$offset, [$id]); }
    public function search(string $term, int $offset): array
    {
        $pattern = '%'.mb_strtolower(str_replace(['!','%','_'], ['!!','!%','!_'], $term)).'%';
        return $this->rows("SELECT t.id,t.title,t.updated_at FROM ce_forum_threads t WHERE LOWER(t.title) LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM ce_forum_posts p WHERE p.thread_id=t.id AND LOWER(p.body) LIKE ? ESCAPE '!') ORDER BY t.updated_at DESC,t.id LIMIT 25 OFFSET ".$offset, [$pattern,$pattern]);
    }
    public function insert(string $id, string $board, string $actor, string $title): void { $this->query('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)', [$id,$board,$actor,$title]); }
    public function touch(string $id): void { $this->query('UPDATE ce_forum_threads SET updated_at=CURRENT_TIMESTAMP WHERE id=?', [$id]); }
    public function moderate(string $id, string $action): void
    {
        $sql = match ($action) {
            'lock' => 'UPDATE ce_forum_threads SET locked=1 WHERE id=?',
            'unlock' => 'UPDATE ce_forum_threads SET locked=0 WHERE id=?',
            'pin' => 'UPDATE ce_forum_threads SET pinned=1 WHERE id=?',
            'unpin' => 'UPDATE ce_forum_threads SET pinned=0 WHERE id=?',
            'delete' => 'DELETE FROM ce_forum_threads WHERE id=?',
            default => throw new \LogicException('Unknown moderation action'),
        };
        $this->query($sql, [$id]);
    }
}
