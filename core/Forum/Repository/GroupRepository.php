<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class GroupRepository extends Repository
{
    private const SELECT_GROUP='SELECT r.id,r.handle,r.name,r.permissions,g.description FROM ce_forum_groups g JOIN ce_roles r ON r.id=g.role_id';
    private static function normalize(array $r): array
    {
        $r['permissions']=json_decode((string)$r['permissions'],true,512,JSON_THROW_ON_ERROR);
        $r['system']=in_array($r['handle'],['forum-member','forum-moderator'],true);
        return $r;
    }
    public function all(): array { return array_map(self::normalize(...),$this->rows(self::SELECT_GROUP.' ORDER BY r.handle')); }
    public function find(string $id): ?array
    {
        $row=$this->row(self::SELECT_GROUP.' WHERE r.id=?',[$id]);
        return $row===null?null:self::normalize($row);
    }
    public function insert(string $id,string $description): void { $this->query('INSERT INTO ce_forum_groups(role_id,description) VALUES(?,?)',[$id,$description]); }
    public function description(string $id,string $description): void { $this->query('UPDATE ce_forum_groups SET description=? WHERE role_id=?',[$description,$id]); }
    public function userExists(string $id): bool { return $this->row('SELECT id FROM ce_users WHERE id=?',[$id])!==null; }
    public function lockUser(string $id): ?array { return $this->lock('ce_users','id=?',[$id]); }
    public function erased(string $id): bool { return $this->row('SELECT user_id FROM ce_forum_erased_accounts WHERE user_id=?',[$id])!==null; }
    public function membership(string $id): array
    {
        $rows=$this->rows('SELECT r.id,r.handle FROM ce_user_roles ur JOIN ce_forum_groups g ON g.role_id=ur.role_id JOIN ce_roles r ON r.id=ur.role_id WHERE ur.user_id=? ORDER BY r.handle',[$id]);
        $ids=array_column($rows,'id');
        $primary=$this->row('SELECT role_id FROM ce_forum_primary_groups WHERE user_id=?',[$id])['role_id']??($ids[0]??null);
        return ['user_id'=>$id,'primary_group_id'=>$primary,'secondary_group_ids'=>array_values(array_filter($ids,static fn($v)=>$v!==$primary))];
    }
    public function assign(string $user,string $primary,array $secondary): void
    {
        $wanted=array_merge([$primary],$secondary);
        $old=$this->membership($user);
        $existing=array_filter(array_merge([$old['primary_group_id']],$old['secondary_group_ids']));
        $this->query('DELETE FROM ce_forum_primary_groups WHERE user_id=?',[$user]);
        foreach (array_diff($existing,$wanted) as $id) $this->query('DELETE FROM ce_user_roles WHERE user_id=? AND role_id=?',[$user,$id]);
        foreach (array_diff($wanted,$existing) as $id) $this->query('INSERT INTO ce_user_roles(user_id,role_id) VALUES(?,?)',[$user,$id]);
        $this->query('INSERT INTO ce_forum_primary_groups(user_id,role_id) VALUES(?,?)',[$user,$primary]);
    }
    public function lockRole(string $id): ?array { return $this->lock('ce_roles','id=?',[$id]); }
    public function inUse(string $id): bool
    {
        if ($this->row('SELECT user_id FROM ce_user_roles WHERE role_id=? LIMIT 1',[$id])) return true;
        foreach (['category','board'] as $kind) if ($this->row("SELECT node_id FROM ce_forum_{$kind}_permissions WHERE subject_type='role' AND subject_id=? LIMIT 1",[$id])) return true;
        return false;
    }
}
