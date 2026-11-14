<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Security;

/** Pure, deterministic ACL evaluator. A parent that cannot be read hides its entire subtree. */
final class PermissionResolver
{
    public const PERMISSIONS=['node:view','thread:read','thread:create','post:reply','post:edit_own','post:edit_any','reaction:use','watch:use','report:create','thread:moderate'];
    public static function evaluate(ForumAccessPolicy $actor, array $roles, array $chain, array $rules): array
    {
        $result=array_fill_keys(self::PERMISSIONS,false);
        if ($actor->has('*')) return array_fill_keys(self::PERMISSIONS,true);
        $result['node:view']=$result['thread:read']=true;
        foreach (['thread:create','post:reply','post:edit_own','reaction:use','watch:use','report:create'] as $permission) $result[$permission]=$actor->has('forum:write');
        $result['post:edit_any']=$actor->has('forum:write') && $actor->has('forum:moderate');
        $result['thread:moderate']=$actor->has('forum:moderate');
        $ceiling=$result; $never=[];
        foreach ($chain as $node) {
            if ($node['visibility']!=='public') $result['thread:read']=false;
            if ($node['visibility']==='hidden') $result['node:view']=false;
            $local=[];
            foreach ($rules[$node['key']]??[] as $rule) {
                $type=$rule['subject_type']; $id=$rule['subject_id'];
                $matches=match($type) {
                    'everyone'=>true, 'guest'=>$actor->userId==='', 'authenticated'=>$actor->userId!=='',
                    'role'=>in_array($id,$roles,true), 'user'=>$actor->userId!=='' && $id===$actor->userId, default=>false,
                };
                if (!$matches) continue;
                $permission=$rule['permission']; $effect=$rule['effect'];
                if ($effect==='never') { $never[$permission]=true; continue; }
                $rank=match($type) {'everyone'=>0,'guest','authenticated'=>1,'role'=>2,'user'=>3,default=>-1};
                $previous=$local[$permission]??[-1,''];
                if ($rank>$previous[0] || ($rank===$previous[0] && $effect==='deny')) $local[$permission]=[$rank,$effect];
            }
            foreach ($local as $permission=>$value) $result[$permission]=$value[1]==='allow';
            foreach ($result as $permission=>$allowed) $result[$permission]=$allowed && $ceiling[$permission] && !isset($never[$permission]);
            if (!$result['node:view'] || !$result['thread:read']) return array_fill_keys(self::PERMISSIONS,false);
        }
        return $result;
    }
}
