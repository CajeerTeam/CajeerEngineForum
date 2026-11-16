<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

final class RateLimiter
{
    public function __construct(private readonly string $rootPath)
    {
    }

    private ?\Redis $redis=null;

    private function distributed(string $bucket,string $identityHash,int $limit,int $window): array
    {
        $key=$bucket.':'.$identityHash;
        try {
            $r=$this->redis??=\CajeerEngine\Cache\RedisConnection::open();
            $prefix=\CajeerEngine\Cache\RedisConnection::namespace();
            $lua=<<<'LUA'
local t=redis.call('TIME'); local now=tonumber(t[1]); local limit=tonumber(ARGV[1]); local window=tonumber(ARGV[2]);
local ttl=redis.call('TTL',KEYS[1]); local hits=tonumber(redis.call('GET',KEYS[1]) or '0');
if ttl<0 then hits=0; redis.call('SET',KEYS[1],0,'EX',window); ttl=window end
local allowed=0; if hits<limit then hits=redis.call('INCR',KEYS[1]); allowed=1 end
return {allowed,math.max(0,limit-hits),now+math.max(1,ttl)}
LUA;
            $result=$r->eval($lua,[$prefix.'rate:'.hash('sha256',$key),(string)$limit,(string)$window],1);
            if(!is_array($result)||count($result)!==3)throw new \RuntimeException('Redis rate limit failed');
            return ['allowed'=>(int)$result[0]===1,'limit'=>$limit,'remaining'=>(int)$result[1],'reset_at'=>(int)$result[2],'retry_after'=>(int)$result[0]===1?0:max(1,(int)$result[2]-time()),'key'=>$key];
        }catch(\Throwable){
            // Never split a cluster-wide security budget across local files after a Redis failure.
            $this->redis=null;
            return ['allowed'=>false,'unavailable'=>true,'limit'=>$limit,'remaining'=>0,'reset_at'=>time()+$window,'retry_after'=>$window,'key'=>$key];
        }
    }

    /** @return array{allowed:bool,limit:int,remaining:int,reset_at:int,retry_after:int,key:string} */
    public function hit(string $bucket, string $identity, int $limit, int $windowSeconds): array
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $identityHash = hash('sha256', $identity);
        if(getenv('RATE_LIMIT_DRIVER')==='redis')return $this->distributed($bucket,$identityHash,$limit,$windowSeconds);
        $dir = $this->rootPath . '/storage/rate-limits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $bucket) . '-' . $identityHash . '.json';
        $now = time();
        $state = ['hits' => 0, 'reset_at' => $now + $windowSeconds];

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return ['allowed' => false, 'limit' => $limit, 'remaining' => 0, 'reset_at' => $now + $windowSeconds, 'retry_after' => $windowSeconds, 'key' => $bucket . ':' . $identityHash];
        }

        try {
            if (!flock($handle, LOCK_EX)) { throw new \RuntimeException('Rate limit lock failed'); }
            // Read the clock after acquiring the inter-process lock.
            $now = time();
            $state = ['hits' => 0, 'reset_at' => $now + $windowSeconds];
            $raw = stream_get_contents($handle);
            if ($raw === false) throw new \RuntimeException('Rate limit read failed');
            if (is_string($raw) && trim($raw) !== '') {
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
}
