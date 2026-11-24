<?php
declare(strict_types=1);
namespace CajeerEngine\Identity;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{AuditLogRepository,RoleRepository,UserRepository,UserSessionRepository};
use PDO;

/** Shared account access, not an independent role or session store. */
final readonly class UnifiedAccess
{
    public function __construct(private DatabaseManager $db, private UserSessionRepository $sessions, private AuditLogRepository $audit) {}

    public static function managed(string $handle): bool
    {
        return in_array($handle,['forum-member','forum-moderator'],true) || str_starts_with($handle,'forum-group-') || str_starts_with($handle,'forum-acp-');
    }

    private function users(): UserRepository { return new UserRepository($this->db,new RoleRepository($this->db)); }

    private function snapshot(string $id): array
    {
        $users=$this->users();
        $user=$users->findById($id)??throw new \DomainException('Пользователь не найден.');
        $roles=(new RoleRepository($this->db))->all();
        $groups=$this->db->connection()->query('SELECT role_id FROM ce_forum_groups')->fetchAll(PDO::FETCH_COLUMN);
        $assigned=[];$available=[];
        foreach($roles as $role){
            $role['managed']=self::managed($role['handle']) || in_array($role['id'],$groups,true);
            if(in_array($role['handle'],$user['roles'],true))$assigned[]=$role;
            if(!$role['managed']&&!in_array('*',$role['permissions'],true))$available[]=$role;
        }
        $blocked=$user['status']!=='active'||$users->forumSuspended($id);
        return ['user_id'=>$id,'status'=>$user['status'],'local_mfa'=>$user['two_factor_enabled'],
            'suspended'=>$users->forumSuspended($id),'roles'=>$assigned,'available_roles'=>$available,
            'effective_scopes'=>$blocked?[]:$user['permissions'], 'protected'=>in_array('*',$user['permissions'],true)||$users->isForumErased($id),
            'revision'=>hash('sha256',json_encode([$user['status'],$assigned,$available],JSON_THROW_ON_ERROR))];
    }

    public function inspect(string $id): array
    {
        self::validateId($id);
        return $this->db->readSnapshot(fn()=> $this->snapshot($id));
    }

    /** Lock both principals in a deterministic order, then check live permissions. */
    private static function validateId(string $id): void
    {
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$id))throw new \InvalidArgumentException('Некорректный UUID.');
    }

    public function mutate(string $actor,string $target,callable $fn): mixed
    {
        self::validateId($actor);self::validateId($target);
        if($actor===$target)throw new \DomainException('Используйте настройки собственной учётной записи.');
        $ids=[$actor,$target];sort($ids,SORT_STRING);$users=$this->users();
        return $users->locked($ids[0],fn()=> $users->locked($ids[1],function()use($users,$actor,$target,$fn){
            $pdo=$this->db->connection();
            if($this->db->driver()!=='sqlite')$pdo->query('SELECT id FROM ce_roles ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $a=$users->findById($actor);$t=$users->findById($target);
            if(!$a||$a['status']!=='active'||$users->forumSuspended($actor)||!in_array('*',$a['permissions'],true))throw new \DomainException('Полномочия администратора отозваны.');
            if(!$t||$users->isForumErased($target))throw new \DomainException('Учётная запись недоступна.');
            return $fn();
        }));
    }

    public function assign(string $actor,string $id,mixed $revision,mixed $handles): array
    {
        if(!is_string($revision)||!preg_match('/^[a-f0-9]{64}$/D',$revision)||!is_array($handles)||!array_is_list($handles)||count($handles)>100)throw new \InvalidArgumentException('Некорректная ревизия или список ролей.');
        foreach($handles as $h)if(!is_string($h)||!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/D',$h)||self::managed($h))throw new \InvalidArgumentException('Недопустимая роль.');
        if(count(array_unique($handles))!==count($handles))throw new \InvalidArgumentException('Повторяющиеся роли.');
        return $this->mutate($actor,$id,function()use($actor,$id,$revision,$handles){
            $pdo=$this->db->connection();
            $before=$this->snapshot($id);
            if($before['protected'])throw new \DomainException('Глобальные администраторы и удалённые аккаунты защищены.');
            if(!hash_equals($before['revision'],$revision))throw new \DomainException('Права изменились. Обновите карточку.');
            $allowed=array_column($before['available_roles'],null,'handle');
            foreach($handles as $h)if(!isset($allowed[$h]))throw new \InvalidArgumentException('Роль не доступна для назначения.');
            $old=array_column(array_filter($before['roles'],static fn($r)=>!$r['managed']),'handle');
            $delete=$pdo->prepare('DELETE FROM ce_user_roles WHERE user_id=? AND role_id=?');
            foreach(array_diff($old,$handles) as $h){if(!isset($allowed[$h]))throw new \DomainException('Защищённая роль.');$delete->execute([$id,$allowed[$h]['id']]);}
            $insert=$pdo->prepare('INSERT INTO ce_user_roles(user_id,role_id) VALUES(?,?)');
            foreach(array_diff($handles,$old) as $h)$insert->execute([$id,$allowed[$h]['id']]);
            if(array_diff($old,$handles)||array_diff($handles,$old)){
                $revoked=$this->sessions->revokeOthers($id,null);
                $this->audit->record('identity.admin_roles_change',$actor,['target_user_id'=>$id,'before'=>array_values($old),'after'=>$handles,'revoked_sessions'=>$revoked]);
            }
            return $this->snapshot($id);
        });
    }

    public function revoke(string $actor,string $id,?string $session): int
    {
        if($session!==null)self::validateId($session);
        return $this->mutate($actor,$id,function()use($actor,$id,$session){
            if($session!==null){
                if(!$this->sessions->revoke($session,$id))throw new \DomainException('Сессия не найдена или уже завершена.');
                $count=1;
            }else $count=$this->sessions->revokeOthers($id,null);
            $this->audit->record('identity.admin_browser_revoke',$actor,['target_user_id'=>$id,'session_id'=>$session,'revoked_sessions'=>$count]);
            return $count;
        });
    }
}
