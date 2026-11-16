<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Cache\{RedisCacheStore,RedisConnection};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumSeoSettings;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse};

/** Cache only anonymous representations. SQL generations are read in the same snapshot as the response. */
final class ForumRuntimeCache
{
    private ?\Redis $redis=null;
    private int $retryAfter=0;
    public function __construct(private readonly DatabaseManager $db,private readonly string $root) {}
    public static function enabled(): bool {return getenv('FORUM_CACHE_ENABLED')==='true';}
    private function redis(): \Redis {return $this->redis??=RedisConnection::open();}
    public function response(Request $request,string $view,callable $load,?ForumSeoSettings $seo=null): Response
    {
        if(!self::enabled()||time()<$this->retryAfter||$request->getMethod()!=='GET'||$request->attributes->get('auth')!==null||$request->headers->has('Authorization')||$request->headers->has('X-API-Token')||$request->cookies->count()>0)return $load();
        $q=$request->query->all();
        // Bound cardinality. Unknown variants (including tracking and unread) are served normally.
        if($view==='latest-cursor'&&$q===['cursor'=>''])$q=[];
        if(array_diff(array_keys($q),['page'])||isset($q['page'])&&(!is_scalar($q['page'])||!preg_match('/\A[1-9][0-9]{0,3}\z/D',(string)$q['page'])))return $load();
        if(!in_array($view,['index','board','thread','latest','latest-cursor','page'],true))return $load();
        $path=$request->getPathInfo();
        $variant=self::pageVariant($view,$path,$q);
        if($view==='page'&&!preg_match('~\A(?:/|/forum/?|/forum/latest/?|/forum/(?:boards|threads)/[a-f0-9-]{36}/?)\z~D',$path))return $load();
        if(!$this->db->tableExists('ce_forum_cache_generation'))return $load();
        try{$prefix=RedisConnection::namespace();$redis=$this->redis();}catch(\Throwable){$this->retryAfter=time()+5;$this->redis=null;return $load();}
        // Never catch and replay application exceptions or SQL failures. Only Redis operations degrade.
        return $this->db->readSnapshot(function()use($prefix,$redis,$request,$view,$q,$path,$load,$seo,$variant){
            $generation=$this->db->connection()->query("SELECT name, generation FROM ce_forum_cache_generation_effective WHERE scope='public' ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
            if(count($generation)!==21)return $load();
            $settings=$view==='page'?[getenv('APP_NAME'),($seo??new ForumSeoSettings($this->root,$this->db,true))->cacheIdentity(),getenv('FORUM_REGISTRATION')]:[getenv('FORUM_REGISTRATION')];
            $key=hash('sha256',json_encode(['v085',$generation,$view,$path,$variant,(string)($q['page']??1),$settings],JSON_THROW_ON_ERROR));
            $store=new RedisCacheStore($redis,$prefix.'cache:');$lock=$prefix.'lock:'.$key;$token=bin2hex(random_bytes(16));$owner=false;
            try{
                $cached=$store->get($key);
                if(is_array($cached)&&isset($cached['body'],$cached['headers'])&&is_string($cached['body'])&&is_array($cached['headers'])){
                    $this->metric($redis,$prefix,'hit');
                    $response=$view==='page'?new Response($cached['body'],200,$cached['headers']):JsonResponse::fromJsonString($cached['body'],200,$cached['headers']);
                    $response->headers->set('X-Forum-Cache','HIT');return $response;
                }
                $this->metric($redis,$prefix,'miss');
                 $owner=(bool)$redis->set($lock,$token,['nx','ex'=>10]);
                // Bound waiting on a concurrent fill; never block a reader for the lock TTL.
                if(!$owner){for($attempt=0;$attempt<3;$attempt++){usleep(20000);$cached=$store->get($key);if(is_array($cached)&&isset($cached['body'],$cached['headers'])&&is_string($cached['body'])&&is_array($cached['headers'])){
                    $this->metric($redis,$prefix,'coalesced');$response=$view==='page'?new Response($cached['body'],200,$cached['headers']):JsonResponse::fromJsonString($cached['body'],200,$cached['headers']);$response->headers->set('X-Forum-Cache','HIT');return $response;
                }}}
            }catch(\Throwable){$this->retryAfter=time()+5;$this->redis=null;return $load();}
            try{
                $response=$load();
                $body=$response->getContent();
                if($owner&&$response->getStatusCode()===200&&is_string($body)&&strlen($body)<=524288&&!$response->headers->has('Set-Cookie')){
                    $headers=[];foreach(['Content-Type','Cache-Control','Vary','X-Robots-Tag'] as $h)if($response->headers->has($h))$headers[$h]=$response->headers->get($h);
                    try{$store->put($key,['body'=>$body,'headers'=>$headers],max(5,min(300,(int)(getenv('FORUM_CACHE_TTL_SECONDS')?:60))));}catch(\Throwable){$this->retryAfter=time()+5;$this->redis=null;}
                }
                $response->headers->set('X-Forum-Cache','MISS');return $response;
            }finally{if($owner)try{$redis->eval("if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('DEL',KEYS[1]) end return 0",[$lock,$token],1);}catch(\Throwable){}}
        });
    }
    /** The default latest HTML is cursor-based; explicit page=1 is numbered. */
    public static function pageVariant(string $view,string $path,array $query): string
    {
        if($view==='page'&&rtrim($path,'/')==='/forum/latest')return array_key_exists('page',$query)?'numbered':'cursor';
        return 'default';
    }
    private function metric(\Redis $r,string $prefix,string $name): void {try{$r->hIncrBy($prefix.'stats',$name,1);}catch(\Throwable){}}
    public static function diagnostics(): array
    {
        $result=['enabled'=>self::enabled(),'rate_limit_driver'=>getenv('RATE_LIMIT_DRIVER')?:'file','queue_driver'=>getenv('QUEUE_DRIVER')?:'file'];
        try{$r=RedisConnection::open();$prefix=RedisConnection::namespace();$info=$r->info('memory');$result+=['ok'=>true,'connected'=>true,'stats'=>$r->hGetAll($prefix.'stats'),'used_memory_bytes'=>(int)($info['used_memory']??0),'maxmemory_bytes'=>(int)($info['maxmemory']??0)];}catch(\Throwable){$result+=['ok'=>!self::enabled()&&getenv('RATE_LIMIT_DRIVER')!=='redis'&&getenv('QUEUE_DRIVER')!=='redis','connected'=>false,'error'=>'Redis unavailable or namespace missing'];}return $result;
    }
}
