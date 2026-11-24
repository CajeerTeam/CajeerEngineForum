<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

final readonly class RateLimiter
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return array{allowed:bool,limit:int,remaining:int,reset_at:int,retry_after:int,key:string} */
    public function hit(string $bucket, string $identity, int $limit, int $windowSeconds): array
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $identityHash = hash('sha256', $identity);
        $driver = getenv('RATE_LIMIT_DRIVER') ?: 'file';
        if ($driver === 'redis') return $this->redisHit($bucket, $identityHash, $limit, $windowSeconds);
        if ($driver !== 'file') return $this->denied($bucket, $identityHash, $limit, $windowSeconds);
        $dir = $this->rootPath . '/storage/rate-limits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $bucket) . '-' . $identityHash . '.json';
        $now = time();
        $state = ['hits' => 0, 'reset_at' => $now + $windowSeconds];

        // Deny symlink substitution and keep identity bucket state owner-private.
        if (is_link($dir) || is_link($file)) {
            return ['allowed' => false, 'limit' => $limit, 'remaining' => 0, 'reset_at' => $now + $windowSeconds, 'retry_after' => $windowSeconds, 'key' => $bucket . ':' . $identityHash];
        }
        if (is_link($this->rootPath.'/storage') || (file_exists($file) && !is_file($file))) return $this->denied($bucket, $identityHash, $limit, $windowSeconds);
        $mask = umask(0077);
        try { $handle = @fopen($file, 'c+'); } finally { umask($mask); }
        if ($handle === false) {
            return ['allowed' => false, 'limit' => $limit, 'remaining' => 0, 'reset_at' => $now + $windowSeconds, 'retry_after' => $windowSeconds, 'key' => $bucket . ':' . $identityHash];
        }

        try {
            if (!flock($handle, LOCK_EX) || !self::sameFile($handle, $file)) { throw new \RuntimeException('Rate limit lock failed'); }
            if (!chmod($file, 0600)) throw new \RuntimeException('Rate limit permissions failed');
            // Read the clock after acquiring the inter-process lock.
            $now = time();
            $state = ['hits' => 0, 'reset_at' => $now + $windowSeconds];
            $raw = stream_get_contents($handle, 4097);
            if ($raw === false || strlen($raw) > 4096) throw new \RuntimeException('Rate limit read failed');
            if (trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded) || !is_int($decoded['hits'] ?? null) || $decoded['hits'] < 0 || !is_int($decoded['reset_at'] ?? null) || $decoded['reset_at'] < 0) {
                    throw new \RuntimeException('Corrupt rate limit state');
                }
                $state = $decoded;
            }

            if ($state['reset_at'] <= $now) {
                $state = ['hits' => 0, 'reset_at' => $now + $windowSeconds];
            }

            $allowed = $state['hits'] < $limit;
            // Saturate at the limit instead of incrementing on every rejected request.
            if ($allowed) $state['hits']++;
            $remaining = max(0, $limit - $state['hits']);
            $retryAfter = $allowed ? 0 : max(1, $state['reset_at'] - $now);

            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            if (!ftruncate($handle, 0) || !rewind($handle) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) throw new \RuntimeException('Rate limit persistence failed');
            flock($handle, LOCK_UN);
            fclose($handle);

            return [
                'allowed' => $allowed,
                'limit' => $limit,
                'remaining' => $remaining,
                'reset_at' => $state['reset_at'],
                'retry_after' => $retryAfter,
                'key' => $bucket . ':' . $identityHash,
            ];
        } catch (\Throwable) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            return ['allowed' => false, 'limit' => $limit, 'remaining' => 0, 'reset_at' => $now + $windowSeconds, 'retry_after' => $windowSeconds, 'key' => $bucket . ':' . $identityHash];
        }
    }
    private function denied(string $bucket, string $hash, int $limit, int $window): array
    {
        return ['allowed'=>false,'limit'=>$limit,'remaining'=>0,'reset_at'=>time()+$window,'retry_after'=>$window,'key'=>$bucket.':'.$hash];
    }

    /** Shared fixed-window admission. Redis expiry and increment are one atomic operation. */
    private function redisHit(string $bucket, string $hash, int $limit, int $window): array
    {
        $redis = null; $started = hrtime(true); $outcome = 'failure';
        try {
            $redis = \CajeerEngine\Cache\RedisConnection::open($this->rootPath);
            $key = \CajeerEngine\Cache\RedisConnection::namespace().'rate:v1:'.hash('sha256',$bucket).':'.$hash;
            $result = $redis->eval(<<<'LUA'
local hits = redis.call('GET', KEYS[1])
local ttl = redis.call('PTTL', KEYS[1])
local cap = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
if not hits then
    redis.call('SET', KEYS[1], '1', 'PX', window)
    return {1, cap - 1, window}
end
if not tonumber(hits) or tonumber(hits) < 0 or tonumber(hits) % 1 ~= 0 or ttl < 0 then
    return redis.error_reply('invalid rate state')
end
if tonumber(hits) >= cap then return {0, 0, ttl} end
local n = redis.call('INCR', KEYS[1])
return {1, cap - n, ttl}
LUA, [$key, $limit, $window * 1000], 1);
            if (!is_array($result) || count($result) !== 3 || !in_array($result[0],[0,1],true) || !is_int($result[1]) || !is_int($result[2])) throw new \RuntimeException('Invalid rate limit response');
            $allowed = $result[0] === 1;
            $seconds = max(1,(int)ceil($result[2]/1000));
            $outcome = $allowed ? 'success' : 'rate_limited';
            return ['allowed'=>$allowed,'limit'=>$limit,'remaining'=>$result[1],'reset_at'=>time()+$seconds,'retry_after'=>$allowed?0:$seconds,'key'=>$bucket.':'.$hash];
        } catch (\Throwable) { return $this->denied($bucket,$hash,$limit,$window); }
        finally {
            if ($redis !== null) try { $redis->close(); } catch (\Throwable) {}
            \CajeerEngine\Observability\Operation::record('security','rate_limit',$outcome,$started,$this->rootPath);
        }
    }

    private static function sameFile($handle, string $path): bool
    {
        clearstatcache(true,$path);
        $fd=fstat($handle); $named=@lstat($path);
        return is_array($fd) && is_array($named) && ($named['mode'] & 0170000) === 0100000 && $named['nlink']===1 && $fd['ino']===$named['ino'] && $fd['dev']===$named['dev'];
    }

    /** Expired counters only; hold the same inode lock as writers, then recheck. */
    public function cleanup(int $limit = 1000): int
    {
        $dir=$this->rootPath.'/storage/rate-limits';
        if (is_link($this->rootPath.'/storage') || is_link($dir)) throw new \RuntimeException('Unsafe rate-limit directory');
        if (!is_dir($dir)) return 0;
        $removed=0;
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ($removed>=max(1,min(10000,$limit))) break;
            if ($file->isLink() || !$file->isFile() || !preg_match('/^[a-zA-Z0-9_.-]+-[a-f0-9]{64}\.json$/D',$file->getFilename())) continue;
            $path=$file->getPathname();
            $handle=@fopen($path,'rb'); if (!$handle) continue;
            try {
                if (!flock($handle,LOCK_EX|LOCK_NB) || !self::sameFile($handle,$path)) continue;
                $raw=stream_get_contents($handle,4097);
                $state=is_string($raw)&&strlen($raw)<=4096?json_decode($raw,true):null;
                if (is_array($state) && is_int($state['reset_at']??null) && $state['reset_at']<=time()-60 && @unlink($path)) $removed++;
            } finally { fclose($handle); }
        }
        return $removed;
    }
}
