<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class PermissionRepository extends Repository
{
    private function table(string $kind): string { return match($kind) {'board'=>'ce_forum_boards','category'=>'ce_forum_categories',default=>throw new \LogicException('Invalid node kind')}; }
    private function rulesTable(string $kind): string { $this->table($kind); return 'ce_forum_'.$kind.'_permissions'; }
    public function node(string $kind,string $id): ?array { return $this->row('SELECT * FROM '.$this->table($kind).' WHERE id=?',[$id]); }
    public function nodes(string $kind): array { return $this->rows('SELECT * FROM '.$this->table($kind)); }
    public function rules(string $kind,?string $id=null): array { return $this->rows('SELECT node_id,subject_type,subject_id,permission,effect FROM '.$this->rulesTable($kind).($id===null?'':' WHERE node_id=?').' ORDER BY node_id,subject_type,subject_id,permission',$id===null?[]:[$id]); }
    public function roles(string $user): array { return array_column($this->rows('SELECT role_id FROM ce_user_roles WHERE user_id=?',[$user]),'role_id'); }
    public function roleRecords(string $user): array { return $this->rows('SELECT r.id,r.handle,r.permissions,g.role_id AS group_id FROM ce_user_roles ur JOIN ce_roles r ON r.id=ur.role_id LEFT JOIN ce_forum_groups g ON g.role_id=r.id WHERE ur.user_id=?',[$user]); }
    public function inspectUser(string $id): ?array { return $this->row('SELECT id,name,status FROM ce_users WHERE id=?',[$id]); }
    public function subjectExists(string $type,string $id): bool { return $this->row('SELECT id FROM '.($type==='role'?'ce_roles':'ce_users').' WHERE id=?',[$id])!==null; }
    public function replace(string $kind,string $id,string $visibility,array $rules): void
    {
        $this->query('UPDATE '.$this->table($kind).' SET visibility=? WHERE id=?',[$visibility,$id]);
        $table=$this->rulesTable($kind);
        $this->query('DELETE FROM '.$table.' WHERE node_id=?',[$id]);
        foreach ($rules as $r) $this->query('INSERT INTO '.$table.' (node_id,subject_type,subject_id,permission,effect) VALUES(?,?,?,?,?)',[$id,$r['subject_type'],$r['subject_id'],$r['permission'],$r['effect']]);
    }
}
