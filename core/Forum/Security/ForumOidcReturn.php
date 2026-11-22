<?php

declare(strict_types=1);

namespace CajeerEngine\Forum\Security;

/** Bind a forum-only post-login destination to the one-time OIDC browser transaction. */
final class ForumOidcReturn
{
    private const MAX_LENGTH = 1024;

    /** Only relative, same-origin forum URLs are permitted; never accept URL authority or fragments. */
    public static function sanitize(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH
            || !str_starts_with($value, '/forum') || str_contains($value, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1) return null;
        $parts = parse_url($value);
        if (!is_array($parts)
            || isset($parts['scheme']) || isset($parts['host']) || isset($parts['port'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return null;
        $path = $parts['path'] ?? '';
        if ($path !== '/forum' && !str_starts_with($path, '/forum/')) return null;
        // Disallow ambiguous normalization (including encoded separators and traversal).
        if (str_contains($path, '%') || str_contains($path, '//')
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) === 1) return null;
        return $value;
    }

    /** The MAC key is the random, browser-only transaction secret, not an application-global key. */
    public static function sign(string $target, string $browser): string
    {
        $safe = self::sanitize($target);
        if ($safe === null || strlen($browser) < 32) throw new \InvalidArgumentException('Invalid forum OIDC return context.');
        $payload = rtrim(strtr(base64_encode($safe), '+/', '-_'), '=');
        return $payload . '.' . hash_hmac('sha256', $payload, $browser);
    }

    /** Invalid or tampered context is ignored; the underlying OIDC state must still validate. */
    public static function verify(mixed $cookie, mixed $browser): ?string
    {
        if (!is_string($cookie) || !is_string($browser) || strlen($cookie) > 1500 || strlen($browser) < 32) return null;
        if (preg_match('/^([A-Za-z0-9_-]{1,1366})\.([a-f0-9]{64})$/D', $cookie, $matches) !== 1) return null;
        if (!hash_equals(hash_hmac('sha256', $matches[1], $browser), $matches[2])) return null;
        $value = base64_decode(strtr($matches[1], '-_', '+/') . str_repeat('=', (4 - strlen($matches[1]) % 4) % 4), true);
        return $value === false ? null : self::sanitize($value);
    }
}
