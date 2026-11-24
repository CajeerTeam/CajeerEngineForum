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
        self::assertSame(intdiv(time(),30), $totp->matchingStep($secret, $totp->code($secret)));
        self::assertNull($totp->matchingStep($secret, 'abcdef'));
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

    public function testTokenExpiryUsesUtcAndFailsClosed(): void
    {
        $old = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Warsaw');
            $parse = \CajeerEngine\Database\Repository\ApiTokenRepository::expiryTimestamp(...);
            self::assertSame(1767225600, $parse('2026-01-01 00:00:00'));
            self::assertSame(1767225600, $parse('2026-01-01 01:00:00+01'));
            self::assertSame(1767225600, $parse('2026-01-01 00:00:00.123456+00:00'));
            foreach ([null, '', 'tomorrow', '2026-02-30 00:00:00'] as $invalid) self::assertSame(0, $parse($invalid));
        } finally { date_default_timezone_set($old); }
    }

    public function testIndependentReleaseIdentity(): void
    {
        $version=new ForumVersion(dirname(__DIR__,2));
        self::assertSame('1.6.0',$version->current());
        self::assertSame('2.1.0',$version->upstream());
    }
}
