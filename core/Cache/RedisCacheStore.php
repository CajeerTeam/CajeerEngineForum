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

    public function put(string $key, mixed $value, int $ttlSeconds = 3600): void
    {
        \CajeerEngine\Observability\Operation::measure('redis','cache.put',fn()=> $this->redis->setex($this->prefix . $key, $ttlSeconds, serialize($value)),$this->metricsRoot,static fn($v)=>$v?'success':'failure');
    }

    public function forget(string $key): void
    {
        \CajeerEngine\Observability\Operation::measure('redis','cache.forget',fn()=> $this->redis->del($this->prefix . $key),$this->metricsRoot,static fn($v)=>$v===false?'failure':'success');
    }
}
