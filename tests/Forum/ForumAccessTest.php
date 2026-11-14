<?php

declare(strict_types=1);
namespace CajeerEngine\Tests\Forum;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Security\ForumAccessPolicy as Policy;
use PHPUnit\Framework\TestCase;

final class ForumAccessTest extends TestCase
{
    public function testAccessMatrix(): void
    {
        $owner = ['author_id'=>'owner'];
        foreach ([
            [new Policy('owner',['forum:write']),0,true],
            [new Policy('owner',['forum:write']),1,false],
            [new Policy('stranger',['forum:write']),0,false],
            [new Policy('owner',[]),0,false],
            [new Policy('mod',['forum:write','forum:moderate']),1,true],
            [new Policy('admin',['*']),1,true],
        ] as [$actor,$locked,$allowed]) {
            try { $actor->assertEdit($owner,['locked'=>$locked]); self::assertTrue($allowed); }
            catch (ForumException $e) { self::assertFalse($allowed); self::assertSame(403,$e->status); }
        }
    }

    public function testAnonymousFailsClosed(): void
    {
        try { Policy::fromAuth(null); self::fail('Anonymous accepted'); }
        catch (ForumException $e) { self::assertSame(401,$e->status); }
    }

    public function testWriteDoesNotGrantAdmin(): void
    {
        $this->expectException(ForumException::class);
        (new Policy('member',['forum:write']))->require('*');
    }
}
