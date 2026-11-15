<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Cache\{RedisCacheStore,RedisConnection};
use CajeerEngine\Database\DatabaseManager;

/** Only integer counts; callers must authorize the current principal inside a SQL snapshot. */
final readonly class ForumCounterCache
{
    public function __construct(private DatabaseManager $db) {}
    public function get(string $user,array $allowed,bool $canWrite,callable $load): array
    {
        if(!$this->db->connection()->inTransaction()||!$this->db->tableExists('ce_forum_cache_generation'))return $load();
        $generation=$this->db->connection()->query('SELECT name,generation FROM ce_forum_cache_generation_effective ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
        if(count($generation)!==27)return $load();
        sort($allowed,SORT_STRING);$key=hash('sha256',json_encode(['v066',$user,$allowed,$canWrite,$generation],JSON_THROW_ON_ERROR));
        try{$r=RedisConnection::open();$prefix=RedisConnection::namespace();$store=new RedisCacheStore($r,$prefix.'counter:');$value=$store->get($key);if(is_array($value)&&isset($value['unread'],$value['conversation_unread'],$value['conversation_total_unread'])){try{$r->hIncrBy($prefix.'stats','counter_hit',1);}catch(\Throwable){}return $value;}}
        catch(\Throwable){return $load();}
        $value=$load();
        try{$store->put($key,$value,30);$r->hIncrBy($prefix.'stats','counter_miss',1);}catch(\Throwable){}
        return $value;
    }
}
