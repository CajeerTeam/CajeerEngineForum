<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

use CajeerEngine\Runtime\ConfigRepository;

/** Explicit host delegation of non-secret settings only. Never delegates trust or execution privileges. */
final readonly class UpdateCenterPolicy
{
    private const KEYS = ['mode','timezone','days','window_start','window_end','defer_until','blocked_versions','channel','sequential','auto_apply'];
    public function __construct(private string $root) {}
    private function path(): string { return OperationFiles::path($this->root,'storage/app/update-requests/center-policy.json'); }
    public function lock(bool $exclusive): mixed
    {
        $dir=dirname($this->path());
        if (!is_dir($dir)) { OperationFiles::dir($dir,02770); chmod($dir,02770); }
        $h=OperationLock::openLockFile($dir.'/center-policy.lock',0660);
        if (!flock($h,($exclusive?LOCK_EX:LOCK_SH)|LOCK_NB)) { fclose($h); throw new OperationBusy('Update policy is in use; retry when the operator is idle'); }
        return $h;
    }
    public static function baseline(ConfigRepository $c): array
    {
        $out=[];
        foreach (array_slice(self::KEYS,0,7) as $key) $out[$key]=$c->string('updates.policy.'.$key,match($key){'mode'=>'legacy','timezone'=>'UTC','days'=>'1,2,3,4,5,6,7',default=>''});
        return $out+['channel'=>$c->string('updates.core.channel','stable'),'sequential'=>$c->bool('updates.core.sequential',true),'auto_apply'=>$c->bool('updates.policy.auto_apply_enabled')];
    }
    private function state(): array
    {
        $p=$this->path();
        if (!file_exists($p)) return ['revision'=>0,'settings'=>null,'history'=>[]];
        if (!is_file($p)||filesize($p)>262144) throw new \RuntimeException('Invalid update policy state');
        $s=OperationFiles::read($p);
        if (!is_int($s['revision']??null)||$s['revision']<1||!array_key_exists('settings',$s)||($s['settings']!==null&&!is_array($s['settings']))||!is_array($s['history']??null)||!array_is_list($s['history'])||count($s['history'])>20) throw new \RuntimeException('Invalid update policy state');
        if ($s['settings']!==null) self::validateShape($s['settings']);
        foreach($s['history'] as $row) if (!is_array($row)||!is_int($row['revision']??null)||!is_string($row['actor']??null)||!is_string($row['at']??null)||!in_array($row['action']??null,['save','reset'],true)) throw new \RuntimeException('Invalid update policy audit');
        return $s;
    }
    private static function validateShape(array $settings): void
    {
        if (count($settings)!==count(self::KEYS)||array_diff(self::KEYS,array_keys($settings))) throw new \InvalidArgumentException('All policy fields are required; unknown fields are forbidden');
        foreach (array_slice(self::KEYS,0,8) as $k) if (!is_string($settings[$k])||strlen($settings[$k])>($k==='blocked_versions'?8192:128)||preg_match('/[\x00-\x1f\x7f]/',$settings[$k])) throw new \InvalidArgumentException('Invalid policy field: '.$k);
        if (!is_bool($settings['sequential'])||!is_bool($settings['auto_apply'])) throw new \InvalidArgumentException('Boolean settings required');
    }
    private static function effective(array $base, array $settings): array
    {
        self::validateShape($settings);
        if (!ReleasePath::accepts($base['channel'],$settings['channel'])) throw new \InvalidArgumentException('Channel exceeds host authorization');
        if ($base['sequential'] && !$settings['sequential']) throw new \InvalidArgumentException('Host requires sequential updates');
        if (!$base['auto_apply'] && $settings['auto_apply']) throw new \InvalidArgumentException('Host has not authorized autonomous installation');
        // Host version blocks are mandatory even when ACP changes its own list.
        $settings['blocked_versions']=implode(',',array_unique(array_filter(array_map('trim',explode(',',$base['blocked_versions'].','.$settings['blocked_versions'])),static fn($v)=>$v!=='')));
        return $settings;
    }
    private static function assign(ConfigRepository $c,array $settings): void
    {
        foreach (array_slice(self::KEYS,0,7) as $k) $c->set('updates.policy.'.$k,$settings[$k]);
        $c->set('updates.core.channel',$settings['channel']); $c->set('updates.core.sequential',$settings['sequential']); $c->set('updates.policy.auto_apply_enabled',$settings['auto_apply']);
    }
    public function apply(ConfigRepository $c,array $base): void
    {
        if (!$c->bool('updates.policy.acp_enabled')) return;
        self::assign($c,$base);
        try {
            $s=$this->state();
            if ($s['settings']!==null) self::assign($c,self::effective($base,$s['settings']));
            if (!(new UpdatePolicy($c))->valid()) throw new \RuntimeException('Invalid effective update policy');
        } catch (\Throwable) {
            self::assign($c,$base); $c->set('updates.policy.mode','disabled'); $c->set('updates.policy.auto_apply_enabled',false);
            $c->set('updates.policy.acp_error',true);
        }
    }
    public function overview(ConfigRepository $c): array
    {
        $base=$c->updatePolicyBaseline(); $enabled=$c->bool('updates.policy.acp_enabled');
        $out=['editable'=>$enabled,'host'=>$base,'baseline_hash'=>hash('sha256',json_encode($base,JSON_THROW_ON_ERROR)),'revision'=>0,'settings'=>self::baseline($c),'history'=>[],'error'=>null];
        if (!$enabled) return $out;
        try { $s=$this->state(); $out['revision']=$s['revision']; $out['settings']=$s['settings']??$base; $out['history']=array_reverse($s['history']); if($c->bool('updates.policy.acp_error')) $out['error']='Policy incompatible with host settings or invalid'; }
        catch (\Throwable) { $out['editable']=false; $out['error']='Policy state unreadable; host intervention required'; }
        return $out;
    }
    public function save(ConfigRepository $c,array $body,string $actor): array
    {
        if (!$c->bool('updates.policy.acp_enabled')) throw new \RuntimeException('Host has not delegated update policy management');
        if ($actor===''||strlen($actor)>160||preg_match('/[\x00-\x1f\x7f]/',$actor)) throw new \InvalidArgumentException('Authenticated actor required');
        if (array_diff(array_keys($body),['revision','baseline_hash','settings','confirm'])||($body['confirm']??null)!==true||!is_int($body['revision']??null)||!is_string($body['baseline_hash']??null)||!array_key_exists('settings',$body)||($body['settings']!==null&&!is_array($body['settings']))) throw new \InvalidArgumentException('Explicit confirmation, revision and settings required');
        $h=$this->lock(true);
        try {
            $s=$this->state(); $base=$c->updatePolicyBaseline();
            if ($s['revision']!==$body['revision']||!hash_equals(hash('sha256',json_encode($base,JSON_THROW_ON_ERROR)),$body['baseline_hash'])) throw new OperationBusy('Policy changed; reload before saving');
            $settings=$body['settings'];
            $candidate=clone $c; self::assign($candidate,$settings===null?$base:self::effective($base,$settings));
            $policy=(new UpdatePolicy($candidate))->snapshot();
            if (!$policy['valid']) throw new \InvalidArgumentException('Invalid policy: '.$policy['error']);
            $revision=$s['revision']+1;
            $s['history'][]=['revision'=>$revision,'actor'=>$actor,'at'=>gmdate(DATE_ATOM),'action'=>$settings===null?'reset':'save','mode'=>$policy['mode'],'channel'=>$candidate->string('updates.core.channel'),'auto_apply'=>$policy['autonomous_apply_enabled']];
            OperationFiles::json($this->path(),['revision'=>$revision,'settings'=>$settings,'history'=>array_slice($s['history'],-20)],0640);
            $c->refreshUpdatePolicy(); return $this->overview($c);
        } finally { OperationLock::release($h); }
    }
}
