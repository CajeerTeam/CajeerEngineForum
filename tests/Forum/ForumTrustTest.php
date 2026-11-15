<?php

declare(strict_types=1);
namespace CajeerEngine\Tests\Forum;

use CajeerEngine\Forum\Service\TrustService;
use PHPUnit\Framework\TestCase;

final class ForumTrustTest extends TestCase
{
    public function testLiveTrustThresholdsAndContactPolicies(): void
    {
        $now=1700000000;
        $user=['created_at'=>gmdate('Y-m-d H:i:s',$now-7*86400),'email_verified_at'=>'yes'];
        self::assertSame('new',TrustService::assess($user,2,$now)['level']);
        $trusted=TrustService::assess($user,3,$now);
        self::assertSame('established',$trusted['level']);
        self::assertSame(20,$trusted['group_participants_max']);
        self::assertSame('new',TrustService::assess($user,3,$now-1)['level']);
        self::assertSame('new',TrustService::assess(array_replace($user,['email_verified_at'=>null]),3,$now)['level']);
        self::assertFalse(TrustService::accepts('trusted',false,true));
        self::assertTrue(TrustService::accepts('trusted',true,false));
        self::assertFalse(TrustService::accepts('following',true,false));
        self::assertTrue(TrustService::accepts('following',false,true));
        self::assertFalse(TrustService::accepts('none',true,true));
    }
}
