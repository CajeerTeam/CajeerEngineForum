<?php
declare(strict_types=1);
namespace CajeerEngine\Security;

/** Operator-mounted credentials; never export file contents into process environment. */
final class SecretEnvironment
{
    public const NAMES = ['INTEGRATIONS_KEY','INTEGRATIONS_PREVIOUS_KEYS','SKIF4ER_ID_TRANSACTION_KEY','SKIF4ER_ID_TRANSACTION_PREVIOUS_KEYS','SKIF4ER_ID_CLIENT_SECRET','OPENAI_API_KEY','DB_PASSWORD','REDIS_PASSWORD','FORUM_SMTP_PASSWORD','OBSERVABILITY_METRICS_TOKEN','OBSERVABILITY_OTLP_TOKEN'];

    public static function get(string $name): string|false
    {
        if (!in_array($name, self::NAMES, true)) throw new \InvalidArgumentException('Unsupported secret name');
        $value = getenv($name);
        $path = getenv($name.'_FILE');
        if ($path === false || $path === '') return $value;
        if ($value !== false && $value !== '') throw new \RuntimeException('Conflicting secret sources: '.$name);
        if (!str_starts_with($path, '/') || str_contains($path, "\0")) throw new \RuntimeException('Invalid secret file: '.$name);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!self::acceptable($stat)) throw new \RuntimeException('Unsafe secret file: '.$name);
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new \RuntimeException('Secret file unavailable: '.$name);
        try {
            $fd = fstat($handle);
            clearstatcache(true, $path);
            $named = @lstat($path);
            if (!self::acceptable($fd) || !self::acceptable($named) || $fd['ino'] !== $named['ino'] || $fd['dev'] !== $named['dev']) throw new \RuntimeException('Unsafe secret file: '.$name);
            $secret = stream_get_contents($handle, 16385);
            if ($secret === false || strlen($secret) > 16384) throw new \RuntimeException('Invalid secret file: '.$name);
            $secret = rtrim($secret, "\r\n");
            if ($secret === '' || preg_match('/[\x00\r\n]/', $secret)) throw new \RuntimeException('Invalid secret file: '.$name);
            return $secret;
        } finally { fclose($handle); }
    }

    private static function acceptable(array|false $stat): bool
    {
        // Owner read/write, optional group read; no group write or world access.
        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && ($stat['mode'] & 0037) === 0 && $stat['size'] > 0 && $stat['size'] <= 16384;
    }

    /** No paths, values, or secret fingerprints in diagnostics. */
    public static function status(): array
    {
        $items = []; $ok = true;
        foreach (self::NAMES as $name) {
            $source = getenv($name.'_FILE') ? 'file' : 'environment';
            try { $value = self::get($name); $items[$name] = ['source'=>$source,'configured'=>$value !== false && $value !== '', 'ok'=>true]; }
            catch (\Throwable) { $items[$name] = ['source'=>$source,'configured'=>false,'ok'=>false]; $ok = false; }
        }
        return ['ok'=>$ok,'items'=>$items];
    }
}
