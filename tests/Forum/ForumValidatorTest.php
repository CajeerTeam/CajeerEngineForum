<?php

declare(strict_types=1);
namespace CajeerEngine\Tests\Forum;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ForumValidatorTest extends TestCase
{
    public function testValidUnicodeAndPagination(): void
    {
        self::assertSame('тема', V::text(' тема ', 'title', 4));
        self::assertSame(0, V::offset('-1'));
        self::assertSame(25, V::offset('2'));
        self::assertSame(249975, V::offset('999999999999999999999999'));
        self::assertSame('22222222-2222-4222-8222-222222222222', V::id('22222222-2222-4222-8222-222222222222'));
        self::assertFalse(V::enabled(false));
        self::assertSame(' Pass123! ', V::password(' Pass123! '));
    }

    public static function invalidText(): iterable
    {
        yield 'array' => [[]];
        yield 'invalid UTF8' => ["\xff"];
        yield 'NUL' => ["ab\0c"];
        yield 'too long' => ['абвгд'];
        yield 'blank' => ['  '];
    }

    #[DataProvider('invalidText')]
    public function testInvalidText(mixed $value): void
    {
        $this->expectException(ForumException::class);
        V::text($value, 'title', 4);
    }

    public function testNoCoercion(): void
    {
        foreach ([fn()=>V::enabled('false'), fn()=>V::id('bad'), fn()=>V::password(['x']), fn()=>V::offset('1.5')] as $i => $call) {
            try { $call(); self::fail('Invalid input accepted'); }
            catch (ForumException $e) { self::assertSame($i===3?400:422, $e->status); }
        }
    }
}
