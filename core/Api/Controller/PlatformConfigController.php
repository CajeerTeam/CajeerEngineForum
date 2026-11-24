<?php

declare(strict_types=1);
namespace CajeerEngine\Api\Controller;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Runtime\PlatformConfiguration;
use CajeerEngine\AI\AiSettings;
use CajeerEngine\Identity\OidcClient;
use CajeerEngine\Security\PublicHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class PlatformConfigController
{
    public function __construct(private ConfigRepository $config) {}
    private function store(): PlatformConfiguration { return new PlatformConfiguration($this->config->rootPath()); }
    private function response(array $data): JsonResponse { return new JsonResponse(['data'=>$data],200,['Cache-Control'=>'no-store']); }
    public function index(Request $request): JsonResponse
    {
        $data=$this->store()->overview($this->config->platformBaseline());
        $env=static fn(string $key): bool=>trim((string)getenv($key))!=='';
        $oidc=filter_var(getenv('SKIF4ER_ID_ENABLED')?:'false',FILTER_VALIDATE_BOOLEAN);
        $ai=new AiSettings($this->config->rootPath());
        try { $status=$ai->status(); $aiReady=$status['configured']; } catch(\Throwable) { $aiReady=false; }
        $data['overrides']=(object)$data['overrides'];
        foreach ($data['history'] as &$entry) { $entry['before']=(object)$entry['before']; $entry['after']=(object)$entry['after']; }
        unset($entry);
        $data['integrations']=[
            'openai'=>['configured'=>$aiReady],
            'skif4er'=>['enabled'=>$oidc,'configured'=>$oidc&&$env('SKIF4ER_ID_CLIENT_ID')&&$env('SKIF4ER_ID_CLIENT_SECRET')],
            'smtp'=>['configured'=>$env('FORUM_SMTP_HOST')&&$env('FORUM_SMTP_FROM')],
            'redis'=>['configured'=>$this->config->string('cache.default')==='redis'||$this->config->string('queue.default')==='redis'||getenv('RATE_LIMIT_DRIVER')==='redis'],
            's3'=>['configured'=>$env('S3_ENDPOINT')&&$env('S3_BUCKET')&&$env('S3_ACCESS_KEY')&&$env('S3_SECRET_KEY'),'active'=>$this->config->string('storage.default')==='s3'],
        ];
        $data['integration_changes']=$this->recentIntegrationChanges();
        return $this->response($data);
    }
    /** Bounded tail of the existing module audit, excluding all context and credential fields. */
    private function recentIntegrationChanges(): array
    {
        $path=$this->config->rootPath().'/storage/logs/audit.jsonl';
        if (!is_file($path)||is_link($path)) return [];
        $h=@fopen($path,'rb'); if(!$h) return [];
        try {
            if(!flock($h,LOCK_SH)) return [];
            $size=fstat($h)['size']??0; $offset=max(0,$size-262144);
            if(fseek($h,$offset)!==0)return [];
            $tail=stream_get_contents($h,262144); if(!is_string($tail))return [];
            $lines=explode("\n",$tail); if($offset>0)array_shift($lines);
            $events=['integration.created','integration.updated','integration.credential_changed','integration.deleted','ai.settings.updated','forum.ai.settings.updated','tracking.config_updated','privacy.policy_updated','analytics.report.created','analytics.report.updated','analytics.report.deleted'];
            $result=[];
            foreach(array_reverse($lines) as $line){
                $entry=json_decode($line,true);
                if(!is_array($entry)||!in_array($entry['event']??null,$events,true))continue;
                $result[]=['event'=>$entry['event'],'actor_id'=>is_string($entry['actor_id']??null)?$entry['actor_id']:null,'created_at'=>is_string($entry['created_at']??null)?$entry['created_at']:null];
                if(count($result)>=30)break;
            }
            return $result;
        } finally {fclose($h);}
    }
    public function update(Request $request): JsonResponse
    {
        $actor=(string)($request->attributes->get('auth')['user_id']??'');
        $this->store()->change($this->config->platformBaseline(),$request->attributes->get('json',[]),$actor);
        return $this->index($request);
    }
    /** Explicit read-only checks; no user-provided host, content, or provider response exposed. */
    public function probe(Request $request): JsonResponse
    {
        $provider=$request->attributes->get('json',[])['provider']??null;
        if(!in_array($provider,['openai','skif4er','redis','smtp'],true)) throw new \InvalidArgumentException('Unknown probe');
        $start=hrtime(true); $ok=false;
        try {
            if($provider==='openai') {
                $ai=new AiSettings($this->config->rootPath()); $model=$ai->status()['model'];
                if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,79}$/D',$model)) throw new \RuntimeException('Invalid model');
                $key=$ai->key();
                try { [$status,$body]=(new PublicHttpClient(5))->request('https://api.openai.com/v1/models/'.rawurlencode($model),'GET',['Authorization'=>'Bearer '.$key],'',65536); $ok=$status===200 && (json_decode($body,true)['id']??null)===$model; }
                finally { sodium_memzero($key); }
            } elseif($provider==='skif4er') {
                if(!filter_var(getenv('SKIF4ER_ID_ENABLED')?:'false',FILTER_VALIDATE_BOOLEAN)) throw new \RuntimeException('Disabled');
                $client=new OidcClient(trim((string)getenv('SKIF4ER_ID_CLIENT_ID')),(string)getenv('SKIF4ER_ID_CLIENT_SECRET'),rtrim($this->config->string('app.url'),'/').'/auth/oidc/callback');
                $ok=$client->probeProvider()['usable_rs256_keys']>0;
            } elseif($provider==='smtp') {
                $ok=(new \CajeerEngine\Forum\Security\IdentityMail())->probe();
            } else {
                $redis=\CajeerEngine\Cache\RedisConnection::open(); try {$ok=(bool)$redis->ping();} finally {$redis->close();}
            }
        }catch(\Throwable){ /* Do not expose remote errors, credentials, or network topology. */ }
        return $this->response(['provider'=>$provider,'ok'=>$ok,'duration_ms'=>round((hrtime(true)-$start)/1e6,2),'checked_at'=>gmdate(DATE_ATOM)]);
    }
}
