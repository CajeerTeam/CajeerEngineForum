<?php

declare(strict_types=1);
namespace CajeerEngine\Runtime;

use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Update\OperationFiles as F;

/** Durable, revisioned non-secret runtime overrides. Environment policy is the ceiling. */
final readonly class PlatformConfiguration
{
    public const KEYS = ['integrations.enabled','integrations.max_connections','integrations.max_payload_bytes','queue.workers.default_limit'];
    public function __construct(private string $root) {}
    private function path(): string { return F::path($this->root, 'storage/app/platform-config/state.json'); }
    private function state(): array
    {
        $path=$this->path();
        if (!file_exists($path)) return ['revision'=>0,'overrides'=>[],'history'=>[]];
        $s=F::read($path);
        if (!is_int($s['revision']??null)||$s['revision']<0||!is_array($s['overrides']??null)||!is_array($s['history']??null)) throw new \RuntimeException('Invalid platform configuration state');
        self::validate($s['overrides']);
        if (count($s['history'])>100 || !array_is_list($s['history'])) throw new \RuntimeException('Invalid configuration history');
        foreach ($s['history'] as $entry) {
            if (!is_array($entry)||array_diff(array_keys($entry),['revision','actor_id','created_at','action','before','after'])||!is_int($entry['revision']??null)||!is_string($entry['actor_id']??null)||!is_string($entry['created_at']??null)||!in_array($entry['action']??null,['update','rollback'],true)||!is_array($entry['before']??null)||!is_array($entry['after']??null)) throw new \RuntimeException('Invalid configuration history entry');
            self::validate($entry['before']); self::validate($entry['after']);
        }
        return $s;
    }
    public static function validate(array $values): void
    {
        foreach ($values as $key=>$value) {
            if (!in_array($key,self::KEYS,true)) throw new \InvalidArgumentException('Unknown or protected platform setting');
            if ($key==='integrations.enabled') {
                if (!is_bool($value)) throw new \InvalidArgumentException('Expected boolean integration flag');
                continue;
            }
            [$min,$max]=match($key) {'integrations.max_connections'=>[1,5000],'integrations.max_payload_bytes'=>[1024,1048576],default=>[1,1000]};
            if (!is_int($value)||$value<$min||$value>$max) throw new \InvalidArgumentException('Invalid platform limit');
        }
    }
    private static function effective(array $base,array $values): array
    {
        $out=$base;
        foreach ($values as $k=>$v) $out[$k]=$k==='integrations.enabled'?($base[$k]&&$v):min($base[$k],$v);
        return $out;
    }
    public function apply(ConfigRepository $config,array $base): void
    {
        foreach(self::effective($base,$this->state()['overrides']) as $k=>$v) $config->set($k,$v);
    }
    private function snapshot(array $base,array $s): array
    {
        return ['revision'=>$s['revision'],'baseline_hash'=>hash('sha256',json_encode($base,JSON_THROW_ON_ERROR)), 'baseline'=>$base,'effective'=>self::effective($base,$s['overrides']),'overrides'=>$s['overrides'],'history'=>array_reverse($s['history'])];
    }
    public function overview(array $base): array { return $this->snapshot($base,$this->state()); }
    public function change(array $base,array $body,string $actor): array
    {
        if ($actor==='') throw new \InvalidArgumentException('Actor required');
        if (!is_int($body['revision']??null)||!is_string($body['baseline_hash']??null)) throw new \InvalidArgumentException('Revision and baseline required');
        $dir=dirname($this->path()); F::dir($dir,0700);
        $lock=F::path($this->root,'storage/app/platform-config/state.lock');
        $h=fopen($lock,'c+'); if(!$h) throw new \RuntimeException('Configuration lock unavailable');
        try {
            F::permissions($lock,0600); if(!flock($h,LOCK_EX)) throw new \RuntimeException('Configuration lock unavailable');
            $s=$this->state(); $current=$this->snapshot($base,$s);
            if($body['revision']!==$s['revision']||!hash_equals($current['baseline_hash'],$body['baseline_hash'])) throw new RevisionConflict('Configuration changed; reload before retrying');
            $rollback=array_key_exists('rollback_revision',$body);
            if($rollback===array_key_exists('overrides',$body)) throw new \InvalidArgumentException('Choose either overrides or rollback');
            if($rollback){
                if(!is_int($body['rollback_revision'])) throw new \InvalidArgumentException('Invalid rollback revision');
                $next=null;
                foreach($s['history'] as $entry) if($entry['revision']===$body['rollback_revision']) $next=$entry['before'];
                if($next===null) throw new \InvalidArgumentException('Rollback revision unavailable');
            }else $next=$body['overrides'];
            if(!is_array($next)) throw new \InvalidArgumentException('Invalid overrides');
            self::validate($next); ksort($next);
            if($next===$s['overrides']) return $current;
            $s['revision']++;
            $s['history'][]=['revision'=>$s['revision'],'actor_id'=>$actor,'created_at'=>gmdate(DATE_ATOM),'action'=>$rollback?'rollback':'update','before'=>$s['overrides'],'after'=>$next];
            $s['history']=array_slice($s['history'],-100); $s['overrides']=$next;
            F::json($this->path(),$s,0600);
            return $this->snapshot($base,$s);
        } finally { fclose($h); }
    }
}
