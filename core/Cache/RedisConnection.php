<?php

declare(strict_types=1);
namespace CajeerEngine\Cache;

/** Shared standalone Redis configuration, bounded I/O and no implicit retry of writes. */
final class RedisConnection
{
    public static function open(?string $root=null): \Redis
    {
        return \CajeerEngine\Observability\Operation::measure('redis','connect',static fn()=>self::connect(),$root);
    }
    private static function connect(): \Redis
    {
        if (!class_exists(\Redis::class)) throw new \RuntimeException('PHP extension redis is required');
        $r=new \Redis();
        try{
            $timeout=max(0.05,min(5.0,(float)(getenv('REDIS_TIMEOUT_SECONDS')?:0.5)));
            if(!$r->connect(getenv('REDIS_HOST')?:'127.0.0.1',(int)(getenv('REDIS_PORT')?:6379),$timeout,null,0,$timeout))throw new \RuntimeException('Redis connection failed');
            $r->setOption(\Redis::OPT_READ_TIMEOUT,$timeout);
            $password=\CajeerEngine\Security\SecretEnvironment::get('REDIS_PASSWORD');$user=getenv('REDIS_USERNAME');
            if($password!==false&&$password!==''&&!$r->auth($user?[$user,$password]:$password))throw new \RuntimeException('Redis authentication failed');
            if(!$r->select((int)(getenv('REDIS_DATABASE')?:0)))throw new \RuntimeException('Redis database selection failed');
            return $r;
        }catch(\Throwable $e){try{$r->close();}catch(\Throwable){}throw $e;}
    }
    public static function namespace(): string
    {
        $namespace=getenv('FORUM_REDIS_NAMESPACE')?:'';
        if(!preg_match('/\A[a-zA-Z0-9_.-]{1,80}\z/D',$namespace))throw new \RuntimeException('Set a unique FORUM_REDIS_NAMESPACE for this installation');
        return 'ce:forum:'.$namespace.':';
    }
}
