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
    /** Recheck the authenticated principal against current account status and roles.
     * For writes the caller already holds the relevant board/tree lock. */
    private function principal(ForumAccessPolicy $actor,bool $lock=false): array
    {
        if ($actor->userId==='') return [$actor->restrict([]),[],false];
        $user=$lock?$this->repo->lockActor($actor->userId):$this->repo->inspectUser($actor->userId);
        if ($user===null || $user['status']!=='active') throw new ForumException('Требуется авторизация.',401);
        if($this->tx->sanctions()->suspended($actor->userId,$lock))throw new ForumException('Аккаунт заблокирован.',401);
        $records=$this->repo->roleRecords($actor->userId,$lock);
        $roles=array_column($records,'id');$scopes=[];$globalModerator=false;
        foreach ($records as $role) {
            $p=json_decode((string)$role['permissions'],true,512,JSON_THROW_ON_ERROR);
            $scopes=array_merge($scopes,$p);
            if (($role['group_id']===null || $role['handle']==='forum-moderator') && (in_array('*',$p,true)||in_array('forum:moderate',$p,true))) $globalModerator=true;
        }
        return [$actor->restrict($scopes),$roles,$globalModerator];
    }
    public function requireAdmin(ForumAccessPolicy $actor,bool $lock=false): void
    {
        $this->requireCurrent($actor,'*',$lock);
    }
    public function requireCurrent(ForumAccessPolicy $actor,string $scope,bool $lock=false): void
    {
        $actor->require($scope);
        [$current]=$this->principal($actor,$lock);
        $current->require($scope);
    }
    /** One ACL snapshot per operation, shared by all queries and pagination. */
    public function access(ForumAccessPolicy $actor, ?string $inspectKey=null, ?array &$trace=null, bool $lockActor=false): array
    {
        [$actor,$roles,$globalModerator]=$this->principal($actor,$lockActor);
        $categories=array_column($this->repo->nodes('category'),null,'id');
        $boards=array_column($this->repo->nodes('board'),null,'id');
        $delegated=$actor->has('forum:moderate')&&!$globalModerator;
        $rules=[];
        foreach (['category','board'] as $kind) foreach ($this->repo->rules($kind) as $r) $rules[$kind.':'.$r['node_id']][]=$r;
        $result=['categories'=>[],'boards'=>[]];
        foreach ($categories as $id=>$category) {
            $category['key']='category:'.$id;
            $detail=$inspectKey===$category['key']?[]:null;
            $result['categories'][$id]=PermissionResolver::evaluate($actor,$roles,[$category],$rules,$delegated,$detail);
            if ($detail!==null) $trace=$detail;
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
            $detail=$inspectKey==='board:'.$id?[]:null;
            $result['boards'][$id]=PermissionResolver::evaluate($actor,$roles,array_reverse($chain),$rules,$delegated,$detail);
            if ($detail!==null) $trace=$detail;
        }
        return $result;
    }
    /** Inspect a fresh browser login, not an arbitrary token's narrower scope. */
    public function inspect(ForumAccessPolicy $actor,string $kind,string $id,?string $userId): array
    {
        $actor->require('*');
        if (!in_array($kind,['category','board'],true)) throw new ForumException('Некорректный тип раздела.',422);
        return $this->tx->read(function () use ($actor,$kind,$id,$userId) {
            $this->requireAdmin($actor);
            $node=$this->repo->node($kind,$id)??throw new ForumException('Раздел не найден.',404);
            $user=$userId===null?null:$this->repo->inspectUser($userId);
            if ($userId!==null && $user===null) throw new ForumException('Пользователь не найден.',404);
            $trace=[];
            $active=$user===null || ($user['status']==='active'&&!$this->tx->sanctions()->suspended($userId));
            $target=new ForumAccessPolicy($userId??'', $userId===null?[]:['*']);
            $access=$active?$this->access($target,$kind.':'.$id,$trace):[];
            $effective=$access[$kind==='board'?'boards':'categories'][$id]??array_fill_keys(PermissionResolver::PERMISSIONS,false);
            if (!$active) { $effective=array_fill_keys(PermissionResolver::PERMISSIONS,false); $trace=['inactive'=>true,'steps'=>[]]; }
            return ['node'=>['id'=>$id,'kind'=>$kind,'name'=>$node['name']],'user'=>$user,'effective'=>$effective,'trace'=>$trace,
                'publication_state'=>$kind==='board'?($this->boards->view($id)['effective_state']??'archived'):null,
                'node_type'=>$node['node_type']??null];
        });
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
        $publication=in_array($permission,['thread:create','post:reply','post:edit_own','post:edit_any'],true);
        ($publication?$this->boards->lockPublicationBoard($board):$this->boards->lockBoard($board)) ?? throw new ForumException('Раздел не найден.',404);
        self::assert($this->access($actor,lockActor:true),$board,$permission);
        $action=match($permission){'thread:create','post:reply','post:edit_own','post:edit_any'=>'posting','reaction:use'=>'reaction',default=>null};
        if($action!==null)$this->tx->sanctions()->enforce($actor->userId,$action,$board);
    }
    private function policy(string $kind,string $id): array
    {
        $row=$this->repo->node($kind,$id)??throw new ForumException('Раздел не найден.',404);
        $rules=array_map(static function(array $r): array { unset($r['node_id']);return $r; },$this->repo->rules($kind,$id));
        $data=['visibility'=>$row['visibility'],'rules'=>$rules];
        return $data+['revision'=>hash('sha256',json_encode($data,JSON_THROW_ON_ERROR))];
    }
    public function get(ForumAccessPolicy $actor,string $kind,string $id): array
    {
        $actor->require('*');
        return $this->tx->read(function () use ($actor,$kind,$id) { $this->requireAdmin($actor); return $this->policy($kind,$id); });
    }
    public function replace(ForumAccessPolicy $actor,string $kind,string $id,mixed $visibility,mixed $rules,mixed $revision=null): array
    {
        $actor->require('*');
        if (!in_array($visibility,['public','private','hidden'],true) || !is_array($rules) || !array_is_list($rules) || count($rules)>500) throw new ForumException('Некорректные правила доступа.');
        if ($revision!==null && (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D',$revision))) throw new ForumException('Некорректная версия правил.',422);
        return $this->tx->write(function () use ($actor,$kind,$id,$visibility,$rules,$revision) {
            $this->boards->lockTree();
            $this->requireAdmin($actor,true);
            $current=$this->policy($kind,$id);
            if ($revision!==null && !hash_equals($current['revision'],$revision)) throw new ForumException('Правила изменены другим администратором. Откройте редактор заново.',409);
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
            return $this->policy($kind,$id);
        });
    }
}
