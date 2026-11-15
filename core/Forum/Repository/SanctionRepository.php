<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class SanctionRepository extends Repository
{
    public function active(string $user,int $now,bool $lock=false): array
    {return $this->rows('SELECT * FROM ce_forum_sanctions WHERE user_id=? AND revoked_at IS NULL AND starts_at<=? AND (ends_at IS NULL OR ends_at>?)'.($lock&&$this->db->driver()==='mysql'?' FOR UPDATE':''),[$user,$now,$now]);}
    public function list(string $user,int $offset,?array $boards=null): array
    {return $this->rows('SELECT s.*,a.id AS appeal_id,a.status AS appeal_status,a.decision AS appeal_decision,a.body AS appeal_body FROM ce_forum_sanctions s LEFT JOIN ce_forum_appeals a ON a.sanction_id=s.id WHERE s.user_id=? AND '.$this->boardFilter('s.board_id',$boards).' ORDER BY s.starts_at DESC,s.id LIMIT 25 OFFSET '.max(0,$offset),[$user]);}
    public function item(string $id): ?array {return $this->row('SELECT * FROM ce_forum_sanctions WHERE id=?',[$id]);}
    public function lockItem(string $id): ?array {return $this->lock('ce_forum_sanctions','id=?',[$id]);}
    public function insert(array $r): void
    {$this->query('INSERT INTO ce_forum_sanctions(id,user_id,issuer_id,board_id,kind,reason,points,starts_at,ends_at,source_warning_id) VALUES(?,?,?,?,?,?,?,?,?,?)',array_values($r));}
    public function revoke(string $id,string $actor,string $reason,int $now): bool
    {return $this->query('UPDATE ce_forum_sanctions SET revoked_at=?,revoked_by=?,revoke_reason=? WHERE id=? AND revoked_at IS NULL',[$now,$actor,$reason,$id])->rowCount()===1;}
    public function appeal(string $id): ?array {return $this->row('SELECT * FROM ce_forum_appeals WHERE sanction_id=?',[$id]);}
    public function insertAppeal(string $id,string $sanction,string $body,int $now): void
    {$this->query('INSERT INTO ce_forum_appeals(id,sanction_id,body,created_at) VALUES(?,?,?,?)',[$id,$sanction,$body,$now]);}
    public function decide(string $id,string $status,string $actor,string $reason,int $now): bool
    {return $this->query('UPDATE ce_forum_appeals SET status=?,decided_by=?,decision=?,decided_at=? WHERE sanction_id=? AND status=\'pending\'',[$status,$actor,$reason,$now,$id])->rowCount()===1;}
    public function appeals(?array $boards,int $offset): array
    {return $this->rows('SELECT a.*,s.user_id,s.kind,s.board_id,s.reason FROM ce_forum_appeals a JOIN ce_forum_sanctions s ON s.id=a.sanction_id WHERE a.status=\'pending\' AND '.$this->boardFilter('s.board_id',$boards).' ORDER BY a.created_at,a.id LIMIT 25 OFFSET '.max(0,$offset));}
    public function revokeDerived(string $warning,string $actor,string $reason,int $now): void
    {$this->query('UPDATE ce_forum_sanctions SET revoked_at=?,revoked_by=?,revoke_reason=? WHERE source_warning_id=? AND revoked_at IS NULL',[$now,$actor,$reason,$warning]);}
    public function revokeSessions(string $user): void
    {$this->query('UPDATE ce_api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE user_id=? AND revoked_at IS NULL',[$user]);}
}
