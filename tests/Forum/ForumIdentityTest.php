<?php

declare(strict_types=1);
namespace CajeerEngine\Tests\Forum;

use CajeerEngine\Security\TwoFactorTotp;
use CajeerEngine\Security\AuthService;
use CajeerEngine\Forum\ForumVersion;
use PHPUnit\Framework\TestCase;

final class ForumIdentityTest extends TestCase
{
    public function testRfc6238Sha1(): void
    {
        $totp = new TwoFactorTotp();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        foreach ([59=>'94287082',1111111109=>'07081804',1111111111=>'14050471',1234567890=>'89005924',2000000000=>'69279037',20000000000=>'65353130'] as $time=>$expected) {
            self::assertSame($expected,$totp->code($secret,$time,8));
        }
        self::assertFalse($totp->verify($secret,'abcdef'));
        $codes=$totp->recoveryCodes();
        self::assertCount(8,array_unique($codes));
        foreach ($totp->hashRecoveryCodes($codes) as $i=>$hash) {
            self::assertNotSame($codes[$i],$hash);
            self::assertTrue(password_verify($codes[$i],$hash));
        }
    }

    public function testScopeAndCsrfBinding(): void
    {
        self::assertSame(['forum:write'],AuthService::intersectScopes(['*'],['forum:write']));
        self::assertSame([],AuthService::intersectScopes(['forum:moderate'],['forum:write']));
        self::assertNotSame(AuthService::csrfToken('first'),AuthService::csrfToken('second'));
    }

    public function testIndependentReleaseIdentity(): void
    {
        $version=new ForumVersion(dirname(__DIR__,2));
        self::assertSame('0.2.3',$version->current());
        self::assertSame('1.3.0',$version->upstream());
    }
}
