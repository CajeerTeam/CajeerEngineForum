<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class ReportRepository extends Repository
{
    public function insert(string $id, string $post, string $actor, string $reason): void { $this->query('INSERT INTO ce_forum_reports(id,post_id,user_id,reason) VALUES(?,?,?,?)', [$id,$post,$actor,$reason]); }
    public function open(int $offset): array { return $this->rows("SELECT r.*,p.thread_id,p.body FROM ce_forum_reports r JOIN ce_forum_posts p ON p.id=r.post_id WHERE r.status='open' ORDER BY r.created_at,r.id LIMIT 25 OFFSET ".$offset); }
    public function lockReport(string $id): ?array { return $this->lock('ce_forum_reports', 'id=?', [$id]); }
    public function resolve(string $id, string $actor): void { $this->query("UPDATE ce_forum_reports SET status='resolved',resolved_by=? WHERE id=?", [$actor,$id]); }
}
