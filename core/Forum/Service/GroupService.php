<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\{BoardRepository,GroupRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;

final readonly class GroupService
{
    public function __construct(private ForumTransaction $tx,private GroupRepository $groups,private RoleRepository $roles,private BoardRepository $boards) {}
    public function all(ForumAccessPolicy $actor): array { $actor->require('*'); return $this->tx->read(function() use($actor) { $this->tx->permissions()->requireAdmin($actor); return $this->groups->all(); }); }
    private function fields(mixed $name,mixed $description,mixed $permissions): array
    {
        $name=V::text($name,'name',120);$description=V::text($description,'description',500,0);
        if (!is_array($permissions)||!array_is_list($permissions)||count($permissions)>2||count(array_unique(array_filter($permissions,'is_string')))!==count($permissions)) throw new ForumException('Некорректные права группы.',422);
        foreach ($permissions as $p) if (!in_array($p,['forum:write','forum:moderate'],true)) throw new ForumException('Группа может предоставлять только форумные права.',422);
        if (in_array('forum:moderate',$permissions,true)&&!in_array('forum:write',$permissions,true)) throw new ForumException('Модерация требует forum:write.',422);
        sort($permissions);return [$name,$description,$permissions];
    }
    public function create(ForumAccessPolicy $actor,mixed $handle,mixed $name,mixed $description,mixed $permissions): array
    {
        $actor->require('*');
        if (!is_string($handle)||!preg_match('/^[a-z][a-z0-9-]{1,59}$/D',$handle)) throw new ForumException('Некорректный handle группы.',422);
        [$name,$description,$permissions]=$this->fields($name,$description,$permissions);$handle='forum-group-'.$handle;
        return $this->tx->write(function() use($actor,$handle,$name,$description,$permissions){
            $this->boards->lockTree();$this->tx->permissions()->requireAdmin($actor,true);
            if ($this->roles->find($handle)!==null) throw new ForumException('Группа уже существует.',409);
            $id=$this->roles->upsert($handle,$name,$permissions);$this->groups->insert($id,$description);
            $this->tx->audit('forum.group.create',$actor->userId,['group_id'=>$id,'permissions'=>$permissions]);
            return $this->groups->find($id)??throw new \LogicException('Group write lost');
        });
    }
    public function update(ForumAccessPolicy $actor,string $id,mixed $name,mixed $description,mixed $permissions): array
    {
        $actor->require('*');[$name,$description,$permissions]=$this->fields($name,$description,$permissions);
        return $this->tx->write(function() use($actor,$id,$name,$description,$permissions){
            $this->boards->lockTree();$this->tx->permissions()->requireAdmin($actor,true);$group=$this->groups->find($id)??throw new ForumException('Группа не найдена.',404);
            if ($group['system']) throw new ForumException('Системная группа защищена.',403);
            $this->roles->upsert($group['handle'],$name,$permissions);$this->groups->description($id,$description);
            $this->tx->audit('forum.group.update',$actor->userId,['group_id'=>$id,'permissions'=>$permissions]);return $this->groups->find($id)??throw new \LogicException('Group write lost');
        });
    }
    public function delete(ForumAccessPolicy $actor,string $id): array
    {
        $actor->require('*');return $this->tx->write(function() use($actor,$id){
            $this->boards->lockTree();$this->tx->permissions()->requireAdmin($actor,true);$group=$this->groups->find($id)??throw new ForumException('Группа не найдена.',404);
            if ($group['system']) throw new ForumException('Системная группа защищена.',403);
            $this->groups->lockRole($id)??throw new ForumException('Группа не найдена.',404);
            if ($this->groups->inUse($id)) throw new ForumException('Сначала удалите назначения и правила доступа группы.',409);
            $this->roles->delete($id);$this->tx->audit('forum.group.delete',$actor->userId,['group_id'=>$id]);return ['id'=>$id];
        });
    }
    public function membership(ForumAccessPolicy $actor,string $user): array
    {
        $actor->require('*');return $this->tx->read(function() use($actor,$user){
            $this->tx->permissions()->requireAdmin($actor);
            if (!$this->groups->userExists($user)) throw new ForumException('Пользователь не найден.',404);
            return $this->groups->membership($user);
        });
    }
    public function assign(ForumAccessPolicy $actor,string $user,mixed $primary,mixed $secondary): array
    {
        $actor->require('*');$primary=V::id($primary);
        if (!is_array($secondary)||!array_is_list($secondary)||count($secondary)>100) throw new ForumException('Некорректный список групп.',422);
        $secondary=array_map([V::class,'id'],$secondary);
        if (in_array($primary,$secondary,true)||count(array_unique($secondary))!==count($secondary)) throw new ForumException('Повторяющиеся группы.',422);
        return $this->tx->write(function() use($actor,$user,$primary,$secondary){
            $this->boards->lockTree();$this->tx->permissions()->requireAdmin($actor,true);
            if (!$this->groups->lockUser($user)) throw new ForumException('Пользователь не найден.',404);
            foreach (array_merge([$primary],$secondary) as $id) {
                $group=$this->groups->find($id)??throw new ForumException('Форумная группа не найдена.',422);
                if (array_diff($group['permissions'],['forum:write','forum:moderate'])!==[]) throw new ForumException('Группа содержит системные права.',409);
            }
            $this->groups->assign($user,$primary,$secondary);
            $this->tx->audit('forum.group.assign',$actor->userId,['user_id'=>$user,'primary_group_id'=>$primary,'secondary_group_ids'=>$secondary]);
            return $this->groups->membership($user);
        });
    }
}
