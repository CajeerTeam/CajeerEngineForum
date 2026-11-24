<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

use CajeerEngine\Runtime\ConfigRepository;

/** Executed only by the idle dedicated operator. Never creates an apply approval. */
final readonly class AutomaticReleaseStaging
{
    public function __construct(private string $root, private ConfigRepository $config, private ?\Closure $work = null) {}

    public function enabled(): bool
    {
        return (new UpdatePolicy($this->config))->stagingEnabled() && $this->config->bool('updates.operator.enabled') && $this->config->bool('updates.web_upgrade.enabled');
    }
    private function path(): string { return OperationFiles::path($this->root,'storage/app/updates/auto-stage.json'); }
    public function state(): array
    {
        try { $s=OperationFiles::read($this->path()); } catch (\Throwable) { $s=[]; }
        return ['enabled'=>$this->enabled()] + array_intersect_key($s,array_flip(['state','version','checked_at','next_attempt_at','failures','reason']));
    }
    public function due(): bool
    {
        if (!$this->enabled() || !is_file($this->root.'/storage/app/installed.lock') || OperationLock::maintenance($this->root)) return false;
        $s=$this->state();
        return (int) ($s['next_attempt_at']??0) <= time();
    }
    public function run(): array
    {
        if (!$this->due()) return ['ok'=>true,'state'=>'skipped'];
        $prior=$this->state(); $now=time();
        // Persist an attempt before the first network request. After SIGKILL the timer retries.
        $this->save(['state'=>'running','next_attempt_at'=>$now+60,'failures'=>(int)($prior['failures']??0)]);
        try {
            if ($this->work !== null) $result=($this->work)();
            else {
                $check=(new UpdateResolver($this->config->get('updates',[]),$this->root))->check((new \CajeerEngine\Forum\ForumVersion($this->root))->current());
                if (!in_array($check['metadata_status']??null,['fresh','not_modified'],true) || ($check['metadata_source']??null)!==GitHubEndpoints::RELEASES_URL) throw new \RuntimeException('Fresh metadata required');
                if (!($check['update_available']??false)) $result=['state'=>'up_to_date'];
                elseif ((new AutonomousUpdate($this->root,$this->config))->quarantined($check['next']['version'])) $result=['state'=>'policy_blocked','version'=>$check['next']['version'],'reason'=>'release_quarantined'];
                elseif (($reason=(new UpdatePolicy($this->config))->stageReason($check['current_version'],$check['next']['version']))!==null) $result=['state'=>'policy_blocked','version'=>$check['next']['version'],'reason'=>$reason];
                else {
                    $download=(new GitHubReleaseDownloader($this->root,$this->config))->download($check['next']['version']);
                    $manager=new UpdateManager($this->root,$this->config);
                    $reason=(new UpdatePolicy($this->config))->stageReason($check['current_version'],$download['version'],$download['security_update']??false);
                    if ($reason!==null) $result=['state'=>'policy_blocked','version'=>$download['version'],'reason'=>$reason];
                    else {
                        $manager->publish($download['package'],null,$download['sha256'],$download['version'],true);
                        $result=['state'=>'staged','version'=>$download['version']];
                    }
                }
            }
            $state=$result+['failures'=>0,'next_attempt_at'=>time()+max(300,min(86400,$this->config->int('updates.core.auto_stage_interval_seconds',3600)))];
            $this->save($state); return ['ok'=>true]+$state;
        } catch (\Throwable) {
            $failures=min(10,max(0,(int)($prior['failures']??0))+1);
            $state=['state'=>'failed','failures'=>$failures,'next_attempt_at'=>time()+min(900,60*(2**($failures-1)))];
            $this->save($state); return ['ok'=>false]+$state;
        }
    }
    private function save(array $state): void
    {
        OperationFiles::json($this->path(),$state+['checked_at'=>gmdate(DATE_ATOM)],0644);
    }
}
