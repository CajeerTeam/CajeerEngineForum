<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class ReportRepository extends Repository
{
    public function insert(string $id, string $post, string $actor, string $reason,string $category='other'): void { $uuid=$this->db->driver()==='pgsql'?'CAST(? AS uuid)':'?';$this->query("INSERT INTO ce_forum_reports(id,post_id,user_id,reason,category,snapshot_body,snapshot_format) SELECT $uuid,$uuid,$uuid,?,?,body,body_format FROM ce_forum_posts WHERE id=?", [$id,$post,$actor,$reason,$category,$post]); }
    public function existing(string $post,string $actor): ?string
    {return $this->row("SELECT id FROM ce_forum_reports WHERE post_id=? AND user_id=? AND status='open' ORDER BY created_at,id LIMIT 1".($this->db->driver()==='sqlite'?'':' FOR UPDATE'),[$post,$actor])['id']??null;}
    public function open(int $offset, array $allowed): array { return $this->rows("SELECT r.*,p.thread_id,p.body FROM ce_forum_reports r JOIN ce_forum_posts p ON p.id=r.post_id JOIN ce_forum_threads t ON t.id=p.thread_id WHERE r.status='open' AND ".$this->boardFilter('t.board_id',$allowed)." ORDER BY r.created_at,r.id LIMIT 25 OFFSET ".$offset); }
    public function own(string $user,int $offset): array
    {return $this->rows('SELECT id,post_id,reason,status,category,created_at,resolved_at,decision_kind FROM ce_forum_reports WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 25 OFFSET '.$offset,[$user]);}
    public function linkSanction(string $id,string $sanction): void
    {$this->query('UPDATE ce_forum_reports SET sanction_id=? WHERE id=?',[$sanction,$id]);}
    public function lockReport(string $id): ?array { return $this->lock('ce_forum_reports', 'id=?', [$id]); }
    public function resolve(string $id, string $actor): void { $this->query("UPDATE ce_forum_reports SET status='resolved',resolved_by=?,resolved_at=?,revision_number=revision_number+1,decision='Resolved',decision_kind='resolved' WHERE id=?", [$actor,time(),$id]); }
}
