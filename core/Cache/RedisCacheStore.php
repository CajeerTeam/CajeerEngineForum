<?php

declare(strict_types=1);

namespace CajeerEngine\Cache;

final readonly class RedisCacheStore implements CacheStoreInterface
{
    public function __construct(private \Redis $redis, private string $prefix = 'ce:cache:', private ?string $metricsRoot=null)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = \CajeerEngine\Observability\Operation::measure('redis','cache.get',function()use($key){$this->redis->clearLastError();return $this->redis->get($this->prefix . $key);},$this->metricsRoot,fn($v)=>$v!==false?'hit':((!$this->redis->isConnected()||$this->redis->getLastError())?'failure':'miss'));
        if ($value === false) {
            return $default;
        }

        return unserialize($value, ['allowed_classes' => false]);
    }

    /** One server round trip for a cache lookup and its best-effort counters.
     * A broken statistics key must never prevent an otherwise valid cache hit.
     */
    public function getCounted(string $key, string $statsKey, string $hit, ?string $miss=null): mixed
    {
        $script=<<<'LUA'
local value=redis.call('GET',KEYS[1])
if value then
    redis.pcall('HINCRBY',KEYS[2],ARGV[1],1)
    return {1,value}
end
if ARGV[2]~='' then redis.pcall('HINCRBY',KEYS[2],ARGV[2],1) end
return {0}
LUA;
        $result=\CajeerEngine\Observability\Operation::measure('redis','cache.get',function()use($script,$key,$statsKey,$hit,$miss){
            $this->redis->clearLastError();
            $result=$this->redis->eval($script,[$this->prefix.$key,$statsKey,$hit,$miss??''],2);
            if(!is_array($result)||!isset($result[0])||!in_array($result[0],[0,1],true)||($result[0]===1&&!is_string($result[1]??null)))throw new \RuntimeException('Redis cache lookup unavailable');
            return $result;
        },$this->metricsRoot,static fn($v)=>$v[0]===1?'hit':'miss');
        return $result[0]===1?@unserialize($result[1],['allowed_classes'=>false]):null;
    }

    public function put(string $key, mixed $value, int $ttlSeconds = 3600): void
    {
        \CajeerEngine\Observability\Operation::measure('redis','cache.put',fn()=> $this->redis->setex($this->prefix . $key, $ttlSeconds, serialize($value)),$this->metricsRoot,static fn($v)=>$v?'success':'failure');
    }

    public function forget(string $key): void
    {
        \CajeerEngine\Observability\Operation::measure('redis','cache.forget',fn()=> $this->redis->del($this->prefix . $key),$this->metricsRoot,static fn($v)=>$v===false?'failure':'success');
    }
}
