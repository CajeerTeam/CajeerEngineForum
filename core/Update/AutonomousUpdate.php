<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Forum\ForumVersion;

/** Dedicated operator only. Host policy is authorization, not an ACP approval.
 * Receipts and attempt journals are private to the updater UID, not the web queue.
 */
final readonly class AutonomousUpdate
{
    public function __construct(private string $root, private ConfigRepository $config,
        private ?\Closure $check = null, private ?\Closure $verify = null, private ?\Closure $apply = null) {}

    private function directory(): string
    {
        $dir=OperationFiles::path($this->root,'storage/app/updates/autonomous');
        OperationFiles::dir($dir,0700);
        clearstatcache(true,$dir);
        if (fileowner($dir)!==posix_geteuid() || (fileperms($dir)&0077)!==0) throw new \RuntimeException('Unsafe autonomous journal permissions');
        return $dir;
    }
    private function privateRead(string $path): array
    {
        clearstatcache(true,$path);
        if (is_link($path) || !is_file($path) || fileowner($path)!==posix_geteuid() || (fileperms($path)&0077)!==0 || lstat($path)['nlink']!==1) throw new \RuntimeException('Unsafe autonomous receipt');
        return OperationFiles::read($path);
    }
    private function current(): string { return (new ForumVersion($this->root))->current(); }
    private function candidate(array $stage): array
    {
        if (!is_string($stage['id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$stage['id'])||!is_string($stage['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$stage['sha256'])) throw new \RuntimeException('Invalid autonomous stage identity');
        $from=ForumVersion::validate($stage['from_version']??null); $to=ForumVersion::validate($stage['version']??null);
        if (ReleasePath::compare($to,$from)<=0) throw new \RuntimeException('Invalid autonomous transition');
        return ['id'=>$stage['id'],'sha256'=>$stage['sha256'],'from_version'=>$from,'version'=>$to];
    }
    /** Called inside publish's approval and operation locks after verification. */
    public function rememberStage(array $stage): void
    {
        OperationFiles::json($this->directory().'/stage.json',$this->candidate($stage));
    }
    /** A running attempt can only be reconciled from the exact durable commit.
     * Unknown outcomes block both manual and autonomous requests until reviewed.
     */
    public function reconcile(): bool
    {
        $dir=OperationFiles::path($this->root,'storage/app/updates/autonomous');
        if (!is_dir($dir)) return true;
        foreach (glob($this->directory().'/attempt-*.json')?:[] as $file) {
            $r=$this->privateRead($file); $c=$this->candidate($r);
            if ($file!==$this->attemptPath($c) || !in_array($r['state']??null,['running','completed','failed'],true)) throw new \RuntimeException('Invalid autonomous attempt');
            if ($r['state']!=='running') continue;
            $last=OperationFiles::path($this->root,'storage/app/updates/last-update.json');
            if (!is_file($last)) return false;
            $report=$this->privateRead($last);
            if (($report['state']??null)==='rolled_back' && $this->current()===$c['from_version'] && is_string($report['id']??null) && preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$report['id'])) {
                $tx=OperationFiles::path($this->root,'storage/app/updates/transactions/'.$report['id'].'/journal.json');
                if (is_file($tx)) {
                    $j=$this->privateRead($tx);
                    if (($j['id']??null)===$report['id'] && ($j['state']??null)==='rolled_back' && ($j['previous_version']??null)===$c['from_version'] && ($j['target_version']??null)===$c['version'] && ($j['sha256']??null)===$c['sha256'] && strtotime((string)($j['at']??''))>=strtotime($r['started_at']) && !OperationLock::active($this->root)) {
                        $r['state']='failed'; $r['transaction_id']=$report['id']; $r['finished_at']=gmdate(DATE_ATOM);
                        OperationFiles::json($file,$r); continue;
                    }
                }
            }
            if (($report['ok']??false)!==true || ($report['state']??null)!=='committed' || ($report['previous_version']??null)!==$c['from_version'] || ($report['target_version']??null)!==$c['version'] || ($report['sha256']??null)!==$c['sha256'] || $this->current()!==$c['version'] || !is_string($report['id']??null) || !preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$report['id'])) return false;
            $tx=OperationFiles::path($this->root,'storage/app/updates/transactions/'.$report['id'].'/journal.json');
            if (!is_file($tx) || OperationLock::active($this->root)) return false;
            $j=$this->privateRead($tx);
            if (($j['id']??null)!==$report['id'] || ($j['state']??null)!=='committed' || ($j['previous_version']??null)!==$c['from_version'] || ($j['target_version']??null)!==$c['version'] || ($j['sha256']??null)!==$c['sha256'] || strtotime((string)($j['at']??''))<strtotime($r['started_at'])) return false;
            $r['state']='completed'; $r['transaction_id']=$report['id']; $r['finished_at']=gmdate(DATE_ATOM);
            OperationFiles::json($file,$r);
        }
        return true;
    }
    private function attemptPath(array $c): string { return $this->directory().'/attempt-'.hash('sha256',$c['from_version'].'>'.$c['version']).'.json'; }
    /** A failed target remains quarantined even if GitHub republishes its digest or the source changes. */
    public function quarantined(string $version): bool
    {
        ForumVersion::validate($version);
        $dir=OperationFiles::path($this->root,'storage/app/updates/autonomous');
        if (!is_dir($dir)) return false;
        foreach (glob($this->directory().'/attempt-*.json')?:[] as $file) {
            $old=$this->privateRead($file); $identity=$this->candidate($old);
            if ($file!==$this->attemptPath($identity) || !in_array($old['state']??null,['running','completed','failed'],true)) throw new \RuntimeException('Invalid quarantine identity');
            if ($old['state']==='failed' && $identity['version']===$version) return true;
        }
        return false;
    }
    /** Called only while UpdateOperator owns the exclusive operator lock. */
    public function run(): array
    {
        $policy=new UpdatePolicy($this->config);
        if (!$policy->autonomousEnabled() || !$this->config->bool('updates.operator.enabled') || !$this->config->bool('updates.web_upgrade.enabled')) return ['state'=>'disabled'];
        if (PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux'||!function_exists('posix_geteuid')||posix_geteuid()===0) throw new \RuntimeException('Dedicated non-root CLI required');
        if (!is_file($this->root.'/storage/app/installed.lock') || OperationLock::maintenance($this->root)) return ['state'=>'deferred','reason'=>'maintenance_or_not_installed'];
        if (!$policy->windowOpen(time())) return ['state'=>'deferred','reason'=>'outside_maintenance_window'];
        $receipt=$this->directory().'/stage.json';
        if (!is_file($receipt)) return ['state'=>'waiting_for_stage'];
        $c=$this->candidate($this->privateRead($receipt));
        if ($c['from_version']!==$this->current()) return ['state'=>'waiting_for_stage'];
        $base=['version'=>$c['version']];
        if (($reason=$policy->autonomousReason($c['from_version'],$c['version'],null,time()))!==null) return $base+['state'=>'deferred','reason'=>$reason];
        $attempt=$this->attemptPath($c);
        if (is_file($attempt)) return $base+['state'=>'blocked','reason'=>'transition_already_attempted'];
        if ($this->quarantined($c['version'])) return $base+['state'=>'blocked','reason'=>'release_quarantined'];
        $retry=$this->directory().'/retry.json';
        if (is_file($retry)) { $r=$this->privateRead($retry); if (($r['id']??null)===$c['id'] && (int)($r['next_attempt_at']??0)>time()) return $base+['state'=>'deferred','reason'=>'retry_cooldown']; }
        $dir=OperationFiles::path($this->root,'storage/app/update-requests');
        $guard=OperationLock::openLockFile($dir.'/approval.lock',0660);
        try {
            if (!flock($guard,LOCK_EX|LOCK_NB)) return $base+['state'=>'deferred','reason'=>'approval_busy'];
            foreach (glob($dir.'/request-*.json')?:[] as $file) if (!in_array(OperationFiles::read($file)['state']??null,['completed','failed'],true)) return $base+['state'=>'deferred','reason'=>'manual_request_pending'];
            $manager=new UpdateManager($this->root,$this->config);
            if (($stage=$manager->staged())===[] || $this->candidate($stage)!==$c) return $base+['state'=>'waiting_for_stage','reason'=>'stage_changed'];
            $package=OperationFiles::path($this->root,'storage/app/updates/inbox/'.$c['id'].'/package.zip');
            // Persist a cooldown before the network call; repeated failures do not hammer GitHub.
            OperationFiles::json($retry,['id'=>$c['id'],'next_attempt_at'=>time()+60]);
            try {
                $check=$this->check!==null?($this->check)($c['from_version']): (new UpdateResolver($this->config->get('updates',[]),$this->root))->check($c['from_version']);
                if (!in_array($check['metadata_status']??null,['fresh','not_modified'],true)||($check['metadata_source']??null)!==GitHubEndpoints::RELEASES_URL) return $base+['state'=>'deferred','reason'=>'fresh_catalog_required'];
                if (($check['next']['version']??null)!==$c['version']) return $base+['state'=>'waiting_for_stage','reason'=>'candidate_changed'];
                $advertised=$check['next']['artifacts']['dist']['sha256']??null;
                if ($advertised!==null && (!is_string($advertised)||!hash_equals($c['sha256'],$advertised))) return $base+['state'=>'waiting_for_stage','reason'=>'catalog_digest_changed'];
                $v=$this->verify!==null?($this->verify)($package,$c['sha256']):$manager->verifyPackage($package,$c['sha256']);
                if (($v['ok']??false)!==true||($v['version']??null)!==$c['version']||($v['sha256']??null)!==$c['sha256']||($v['mode']??null)!=='dist') return $base+['state'=>'deferred','reason'=>'package_verification_failed'];
                if (($reason=(new UpdatePolicy($this->config))->autonomousReason($c['from_version'],$c['version'],($v['security_update']??false)===true,time()))!==null) return $base+['state'=>'deferred','reason'=>$reason];
            } catch (\Throwable) { return $base+['state'=>'deferred','reason'=>'admission_unavailable']; }
            if ($this->current()!==$c['from_version'] || OperationLock::maintenance($this->root)) return $base+['state'=>'deferred','reason'=>'runtime_changed'];
            // Durable at-most-once admission. Never retry an ambiguous mutation.
            $r=$c+['state'=>'running','started_at'=>gmdate(DATE_ATOM)]; OperationFiles::json($attempt,$r);
            try {
                $options=['package'=>$package,'sha256'=>$c['sha256'],'version'=>$c['version'],'autonomous'=>true,'from_version'=>$c['from_version']];
                $result=$this->apply!==null?($this->apply)($options):$manager->apply($options);
                if (($result['deferred']??false)===true && !OperationLock::maintenance($this->root) && $this->current()===$c['from_version']) {
                    OperationFiles::json($retry,['id'=>$c['id'],'next_attempt_at'=>time()+60]);
                    OperationFiles::remove($attempt);
                    return $base+['state'=>'deferred','reason'=>$result['reason']??'preflight_deferred'];
                }
                if (($result['ok']??false)===true) {
                    if (!$this->reconcile()) return $base+['state'=>'recovery_required','reason'=>'commit_unverified'];
                    return $base+['state'=>'completed','transaction_id'=>$result['id']??null];
                }
                if (OperationLock::maintenance($this->root) || $this->current()!==$c['from_version']) return $base+['state'=>'recovery_required','reason'=>'apply_outcome_unknown'];
                $r['state']='failed'; $r['finished_at']=gmdate(DATE_ATOM); OperationFiles::json($attempt,$r);
                return $base+['state'=>'failed','reason'=>'apply_failed'];
            } catch (\Throwable) {
                // A thrown operation may have committed. Leave the journal running
                // unless the original version and absence of recovery state prove a safe failure.
                if (OperationLock::maintenance($this->root)||$this->current()!==$c['from_version']) return $base+['state'=>'recovery_required','reason'=>'apply_outcome_unknown'];
                $r['state']='failed'; $r['finished_at']=gmdate(DATE_ATOM); OperationFiles::json($attempt,$r);
                return $base+['state'=>'failed','reason'=>'apply_failed'];
            }
        } finally { OperationLock::release($guard); }
    }
}
