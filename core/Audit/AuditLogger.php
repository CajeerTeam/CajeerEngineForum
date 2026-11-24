<?php

declare(strict_types=1);

namespace CajeerEngine\Audit;

final readonly class AuditLogger
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param array<string, mixed> $context */
    public function record(string $event, ?string $actorId = null, array $context = []): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать директорию аудита.');
        }

        $entry = [
            'id' => self::uuid(),
            'event' => $event,
            'actor_id' => $actorId,
            'context' => AuditContext::sanitize($context),
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $handle = $this->openLog('ab');
        try {
            if (!flock($handle, LOCK_EX) || !self::sameFile($handle, $path) || !@chmod($path, 0600)) throw new \RuntimeException('Audit log unavailable');
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) throw new \RuntimeException('Audit log write failed');
        } finally { fclose($handle); }
    }

    public function count(): int
    {
        $path = $this->path();
        if (!file_exists($path) && !is_link($path)) {
            return 0;
        }

        $count = 0;
        $handle = $this->openLog('rb');
        if (!flock($handle, LOCK_SH)) { fclose($handle); throw new \RuntimeException('Audit log unavailable'); }
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $count++;
            }
        }
        fclose($handle);
        return $count;
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 20): array
    {
        $path = $this->path();
        if (!file_exists($path) && !is_link($path)) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $handle = $this->openLog('rb');
        $lines = [];
        try {
            if (!flock($handle, LOCK_SH)) throw new \RuntimeException('Audit log unavailable');
            // Read only the final 4 MiB; never load an unbounded audit file.
            $size = fstat($handle)['size'];
            $offset = max(0, $size - 4194304);
            fseek($handle, $offset);
            if ($offset > 0) fgets($handle);
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') continue;
                $lines[] = $line;
                if (count($lines) > $limit) array_shift($lines);
            }
        } finally { fclose($handle); }
        $items = [];
        foreach ($lines as $line) {
            try {
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $decoded['context'] = AuditContext::sanitize(is_array($decoded['context'] ?? null) ? $decoded['context'] : []);
                    $items[] = $decoded;
                }
            } catch (\JsonException) {
                continue;
            }
        }

        return array_reverse($items);
    }

    private function openLog(string $mode)
    {
        $path = $this->path();
        if (is_link($this->rootPath.'/storage') || is_link(dirname($path)) || is_link($path)) throw new \RuntimeException('Unsafe audit path');
        $stat = @lstat($path);
        if (is_array($stat) && (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1)) throw new \RuntimeException('Unsafe audit file');
        $mask = umask(0077);
        try { $handle = @fopen($path, $mode); } finally { umask($mask); }
        if ($handle === false) throw new \RuntimeException('Audit log unavailable');
        if (!self::sameFile($handle, $path)) { fclose($handle); throw new \RuntimeException('Unsafe audit file'); }
        return $handle;
    }

    private static function sameFile($handle, string $path): bool
    {
        clearstatcache(true, $path);
        $fd = fstat($handle); $stat = @lstat($path);
        return is_array($fd) && is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && $stat['ino'] === $fd['ino'] && $stat['dev'] === $fd['dev'] && $stat['nlink'] === 1;
    }

    private function path(): string
    {
        return $this->rootPath . '/storage/logs/audit.jsonl';
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}
