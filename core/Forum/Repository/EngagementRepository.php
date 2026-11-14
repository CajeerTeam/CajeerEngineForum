<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class EngagementRepository extends Repository
{
    public function reaction(string $post, string $actor, bool $enabled): bool { return $this->set('ce_forum_reactions', 'post_id', $post, $actor, $enabled); }
    public function watch(string $thread, string $actor, bool $enabled): bool { return $this->set('ce_forum_watches', 'thread_id', $thread, $actor, $enabled); }
    private function set(string $table, string $column, string $id, string $actor, bool $enabled): bool
    {
        if (!$enabled) return $this->query("DELETE FROM $table WHERE $column=? AND user_id=?", [$id,$actor])->rowCount() > 0;
        $sql = $this->db->driver() === 'mysql'
            ? "INSERT INTO $table($column,user_id) VALUES(?,?) ON DUPLICATE KEY UPDATE user_id=user_id"
            : "INSERT INTO $table($column,user_id) VALUES(?,?) ON CONFLICT ($column,user_id) DO NOTHING";
        return $this->query($sql, [$id,$actor])->rowCount() > 0;
    }
    public function watches(string $actor, int $offset, array $allowed): array { return $this->rows('SELECT t.id,t.title,t.prefix,t.updated_at FROM ce_forum_watches w JOIN ce_forum_threads t ON t.id=w.thread_id WHERE w.user_id=? AND '.$this->boardFilter('t.board_id',$allowed).' ORDER BY t.updated_at DESC,t.id LIMIT 25 OFFSET '.$offset, [$actor]); }
}
