<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\{BoardRepository,PermissionRepository};
use CajeerEngine\Forum\Security\{ForumAccessPolicy,PermissionResolver};
use CajeerEngine\Forum\Validation\ForumValidator as V;

final readonly class NodePermissionService
{
    public function __construct(private ForumTransaction $tx,private PermissionRepository $repo,private BoardRepository $boards) {}
    /** One ACL snapshot per operation, shared by all queries and pagination. */
    public function access(ForumAccessPolicy $actor): array
    {
        $categories=array_column($this->repo->nodes('category'),null,'id');
        $boards=array_column($this->repo->nodes('board'),null,'id');
        $records=$actor->userId===''?[]:$this->repo->roleRecords($actor->userId);
        $roles=array_column($records,'id');$scopes=[];$globalModerator=false;
        foreach ($records as $role) {
            $p=json_decode((string)$role['permissions'],true,512,JSON_THROW_ON_ERROR);
            $scopes=array_merge($scopes,$p);
            if (($role['group_id']===null || $role['handle']==='forum-moderator') && (in_array('*',$p,true)||in_array('forum:moderate',$p,true))) $globalModerator=true;
        }
        $actor=$actor->restrict($scopes);
        $delegated=$actor->has('forum:moderate')&&!$globalModerator;
        $rules=[];
        foreach (['category','board'] as $kind) foreach ($this->repo->rules($kind) as $r) $rules[$kind.':'.$r['node_id']][]=$r;
        $result=['categories'=>[],'boards'=>[]];
        foreach ($categories as $id=>$category) {
            $category['key']='category:'.$id;
            $result['categories'][$id]=PermissionResolver::evaluate($actor,$roles,[$category],$rules,$delegated);
        }
        foreach ($boards as $id=>$board) {
            $chain=[];$seen=[];$cursor=$board;
            while (true) {
                if (isset($seen[$cursor['id']]) || count($seen)>=16 || $cursor['category_id']!==$board['category_id']) continue 2;
                $seen[$cursor['id']]=true;$cursor['key']='board:'.$cursor['id'];$chain[]=$cursor;
                if ($cursor['parent_id']===null) break;
                if (!isset($boards[$cursor['parent_id']])) continue 2;
                $cursor=$boards[$cursor['parent_id']];
            }
            $category=$categories[$board['category_id']]??null;if ($category===null) continue;
            $category['key']='category:'.$category['id'];$chain[]=$category;
            $result['boards'][$id]=PermissionResolver::evaluate($actor,$roles,array_reverse($chain),$rules,$delegated);
        }
        return $result;
    }
    public static function allowed(array $access,string $permission='thread:read',string $kind='boards'): array
    { return array_keys(array_filter($access[$kind],fn(array $p)=>$p['node:view'] && $p['thread:read'] && ($p[$permission]??false))); }
    public static function assert(array $access,string $board,string $permission): void
    {
        $p=$access['boards'][$board]??[];
        if (!($p['node:view']??false) || !($p['thread:read']??false)) throw new ForumException('Раздел не найден.',404);
        if (!($p[$permission]??false)) throw new ForumException('Недостаточно прав.',403);
    }
    /** Board lock serializes with ACL and topology mutations; check only after acquiring it. */
    public function assertWrite(ForumAccessPolicy $actor,string $board,string $permission): void
    {
        $this->boards->lockBoard($board) ?? throw new ForumException('Раздел не найден.',404);
        self::assert($this->access($actor),$board,$permission);
    }
    public function get(ForumAccessPolicy $actor,string $kind,string $id): array
    {
        $actor->require('*');
        return $this->tx->read(function () use ($kind,$id) {
            $row=$this->repo->node($kind,$id)??throw new ForumException('Раздел не найден.',404);
            return ['visibility'=>$row['visibility'],'rules'=>array_map(static function(array $r): array { unset($r['node_id']);return $r; },$this->repo->rules($kind,$id))];
        });
    }
    public function replace(ForumAccessPolicy $actor,string $kind,string $id,mixed $visibility,mixed $rules): array
    {
        $actor->require('*');
        if (!in_array($visibility,['public','private','hidden'],true) || !is_array($rules) || !array_is_list($rules) || count($rules)>500) throw new ForumException('Некорректные правила доступа.');
        return $this->tx->write(function () use ($actor,$kind,$id,$visibility,$rules) {
            $this->boards->lockTree();
            $this->repo->node($kind,$id)??throw new ForumException('Раздел не найден.',404);
            $normalized=[];$seen=[];
            foreach ($rules as $r) {
                if (!is_array($r)) throw new ForumException('Некорректное правило.');
                $type=$r['subject_type']??null;$subject=$r['subject_id']??'';$permission=$r['permission']??null;$effect=$r['effect']??null;
                if (!in_array($type,['everyone','guest','authenticated','role','user'],true) || !in_array($permission,PermissionResolver::PERMISSIONS,true) || !in_array($effect,['inherit','allow','deny','never'],true)) throw new ForumException('Некорректное правило.');
                if (in_array($type,['role','user'],true)) { $subject=V::id($subject); if (!$this->repo->subjectExists($type,$subject)) throw new ForumException('Субъект не найден.'); }
                elseif ($subject!=='') throw new ForumException('Некорректный субъект.');
                $key=$type.':'.$subject.':'.$permission;if (isset($seen[$key])) throw new ForumException('Повторяющееся правило.');$seen[$key]=true;
                if ($effect!=='inherit') $normalized[]=['subject_type'=>$type,'subject_id'=>$subject,'permission'=>$permission,'effect'=>$effect];
            }
            $this->repo->replace($kind,$id,$visibility,$normalized);
            $this->tx->audit('forum.node.permissions',$actor->userId,['kind'=>$kind,'id'=>$id,'visibility'=>$visibility,'rules'=>$normalized]);
            return ['visibility'=>$visibility,'rules'=>$normalized];
        });
    }
}
