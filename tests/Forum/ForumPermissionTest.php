<?php

declare(strict_types=1);
namespace CajeerEngine\Tests\Forum;

use CajeerEngine\Forum\Security\{ForumAccessPolicy,PermissionResolver};
use PHPUnit\Framework\TestCase;

final class ForumPermissionTest extends TestCase
{
    private function rule(string $permission,string $effect,string $type='everyone',string $id=''): array
    { return ['permission'=>$permission,'effect'=>$effect,'subject_type'=>$type,'subject_id'=>$id]; }
    private function chain(string $parent='public',string $child='public'): array
    { return [['key'=>'parent','visibility'=>$parent],['key'=>'child','visibility'=>$child]]; }
    public function testPrivateAndHiddenAncestorsFailClosed(): void
    {
        $member=new ForumAccessPolicy('member',['forum:write']);
        $allow=$this->rule('thread:read','allow','user','member');
        self::assertFalse(PermissionResolver::evaluate($member,[],$this->chain('private'),['child'=>[$allow]])['thread:read']);
        self::assertTrue(PermissionResolver::evaluate($member,[],$this->chain('private'),['parent'=>[$allow]])['thread:read']);
        self::assertFalse(PermissionResolver::evaluate($member,[],$this->chain('hidden'),['parent'=>[$allow]])['thread:read']);
        self::assertTrue(PermissionResolver::evaluate($member,[],$this->chain('hidden'),['parent'=>[$allow,$this->rule('node:view','allow','user','member')]])['thread:read']);
    }
    public function testPrecedenceAndNever(): void
    {
        $actor=new ForumAccessPolicy('member',['forum:write']);
        $rules=['parent'=>[$this->rule('post:reply','deny')],'child'=>[$this->rule('post:reply','allow','role','vip')]];
        self::assertTrue(PermissionResolver::evaluate($actor,['vip'],$this->chain(),$rules)['post:reply']);
        $rules['child'][]=$this->rule('post:reply','deny','role','other');
        self::assertFalse(PermissionResolver::evaluate($actor,['vip','other'],$this->chain(),$rules)['post:reply']);
        $rules['child'][]=$this->rule('post:reply','allow','user','member');
        self::assertTrue(PermissionResolver::evaluate($actor,['vip','other'],$this->chain(),$rules)['post:reply']);
        $rules['parent'][]=$this->rule('post:reply','never');
        self::assertFalse(PermissionResolver::evaluate($actor,['vip','other'],$this->chain(),$rules)['post:reply']);
    }
    public function testGlobalScopeCeilingAndAdmin(): void
    {
        $rule=$this->rule('thread:moderate','allow');
        self::assertFalse(PermissionResolver::evaluate(new ForumAccessPolicy('member',['forum:write']),[],$this->chain(),['parent'=>[$rule]])['thread:moderate']);
        self::assertFalse(PermissionResolver::evaluate(new ForumAccessPolicy('',[]),[],$this->chain(),['parent'=>[$this->rule('post:reply','allow')]])['post:reply']);
        self::assertTrue(PermissionResolver::evaluate(new ForumAccessPolicy('admin',['*']),[],$this->chain('hidden'),['parent'=>[$this->rule('thread:read','never')]])['thread:moderate']);
    }
}
