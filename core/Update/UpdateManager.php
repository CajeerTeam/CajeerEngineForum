<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

use CajeerEngine\Backup\BackupManager;
use CajeerEngine\Backup\DatabaseBackup;
use CajeerEngine\Release\ReleaseArtifactVerifier;
use CajeerEngine\Release\ReleaseFilePolicy;
use CajeerEngine\Runtime\ConfigRepository;

/** Single-node updater. All mutations are journalled under one exclusive activity lease. */
final class UpdateManager
{
    public function __construct(private readonly string $rootPath, private readonly ConfigRepository $config) {}

    public function check(): array
    {
        $current=$this->currentVersion();
        $check=(new UpdateResolver($this->config->get('updates',[]),$this->rootPath))->check($current);
        return $check+['ok'=>true,'current_version'=>$current,'latest_version'=>$check['latest']['version']??null,'maintenance'=>$this->maintenanceState(),'production_apply_enabled'=>$this->trustConfigured(),'policy'=>(new UpdatePolicy($this->config))->snapshot(),'safe_update_command'=>'php bin/cajeer update:apply --package=/path/release-dist.zip --version=APPROVED_VERSION --sha256=APPROVED_SHA256 --dry-run'];
    }
    public function plan(array $options=[]): array
    {
        $package=$this->option($options,'package'); $check=$package===null?$this->check():[];
        if($package===null && $this->option($options,'version')!==null && $this->option($options,'version')!==($check['next']['version']??null))throw new \RuntimeException('Requested version is not the next eligible release');
        $plan=['ok'=>true,'current_version'=>$this->currentVersion(),'target_version'=>$this->option($options,'version')??($check['next']['version']??null),'package'=>$package,'mode'=>'dist','backup_before_update'=>true,'code_snapshot_before_update'=>true,'maintenance_mode_during_apply'=>true,'migrations_after_apply'=>true,'post_update_doctor'=>true,'production_apply_enabled'=>$this->trustConfigured(),'update_available'=>$check['update_available']??false,'upgrade_path'=>$check['upgrade_path']??[],'checked_at'=>gmdate(DATE_ATOM)];
        if ($package!==null && PHP_SAPI==='cli') {
            $verified=$this->verifyPackage($this->resolvePackage($package),$this->option($options,'sha256'),$this->option($options,'signature'));
            if (!$verified['ok']) throw new \RuntimeException($verified['error']);
            UpdatePreflight::identity($verified['manifest'],$this->currentVersion(),$this->channel(),$this->option($options,'version'));
            $plan['target_version']=$verified['version']; $plan['expected_sha256']=$verified['sha256']; $plan['file_count']=$verified['file_count'];
            $plan['runtime_compatibility']=UpdateCompatibility::inspect($verified['manifest'],$this->config);
            $plan['extension_compatibility']=(new \CajeerEngine\Extension\ExtensionRegistry($this->rootPath,$this->config))->targetCompatibility($verified['version'],$verified['manifest']['engine_api_version']);
            $plan['ok']=$plan['runtime_compatibility']['ok'] && $plan['extension_compatibility']['ok'];
        }
        return $plan;
    }
    public function prepare(array $options=[]): array
    {
        $plan=$this->plan($options); $dir=$this->path('plans'); OperationFiles::dir($dir,0770);
        $path=$dir.'/plan-'.bin2hex(random_bytes(12)).'.json'; OperationFiles::json($path,$plan);
        return $plan+['plan_file'=>$path];
    }
    /** Authenticate the announced digest before allocating a large partial download. */
    public function verifyReleaseDigest(string $digest, string $signature, ?string $version=null): array
    {
        return ReleaseTrust::configured($this->rootPath)->verify($digest,$signature,$version,$version===null?null:'dist');
    }
    public function verifyPackage(string $packagePath, ?string $expectedSha=null, ?string $signature=null): array
    {
        try {
            if (!is_file($packagePath)||is_link($packagePath)||filesize($packagePath)>2*1024*1024*1024) throw new \RuntimeException('Invalid or oversized ZIP');
            $digest=OperationFiles::hash($packagePath);
            if ($expectedSha!==null&&!hash_equals($digest,strtolower($expectedSha))) throw new \RuntimeException('Release signature or SHA256 mismatch');
            $sig=ReleaseTrust::configured($this->rootPath)->verify($digest,$signature??$packagePath.'.sig');
            $zip=new \PharData($packagePath); $name='cajeerengine/release-manifest.json';
            if (!isset($zip[$name])||$zip[$name]->getSize()>16*1024*1024) throw new \RuntimeException('Missing manifest');
            $m=json_decode($zip[$name]->getContent(),true,512,JSON_THROW_ON_ERROR);
            if (($m['edition'] ?? '') !== 'forum' || !isset($m['upstream_version'])) throw new \RuntimeException('Forum Edition release required; CMS packages cannot be applied.');
            $version=$m['version']??''; $mode=$m['mode']??'';
            if (!is_string($version)||!preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D',$version)||!in_array($mode,['source','dist'],true)) throw new \RuntimeException('Invalid release identity');
            ReleaseTrust::identity($sig,$version,$mode);
            $m=(new ReleaseArtifactVerifier())->verify($packagePath,$mode,$version);
            $release=json_decode($zip['cajeerengine/release.json']->getContent(),true,512,JSON_THROW_ON_ERROR);
            if (($release['channel']??null)!==UpdatePreflight::channelOf($version)) throw new \RuntimeException('Signed release channel differs from version');
            if (!is_string($m['engine_api_version']??null)||!preg_match('/^\d+\.\d+(?:\.\d+)?$/D',$m['engine_api_version'])||($release['engine_api_version']??null)!==$m['engine_api_version']) throw new \RuntimeException('Invalid target Engine API identity');
            if (array_key_exists('security_update',$release) && !is_bool($release['security_update'])) throw new \RuntimeException('Invalid signed security classification');
            if (array_key_exists('upgrade_compatibility',$release)) {
                UpdateCompatibility::validate($release['upgrade_compatibility'],$version);
                $m['upgrade_compatibility']=$release['upgrade_compatibility'];
            }
            foreach ($m['removed_files']??[] as $file) if (!is_string($file)||!$this->managed($file)||in_array($file,$m['files'],true)) throw new \RuntimeException('Invalid removed file');
            if (version_compare(PHP_VERSION,'8.4.1','<')||version_compare(PHP_VERSION,'9.0','>=')) throw new \RuntimeException('Unsupported PHP runtime');
            return ['ok'=>true,'package'=>$packagePath,'version'=>$version,'mode'=>$mode,'manifest'=>$m,'security_update'=>($release['security_update']??false)===true,'file_count'=>count($m['files'])]+$sig;
        } catch (\Throwable $e) { return ['ok'=>false,'error'=>$e->getMessage()]; }
    }
    /** Compare installed managed files to an independently authenticated release ZIP. */
    public function integrity(string $package,?string $expectedSha=null,?string $signature=null): array
    {
        $this->cliOnly();
        $lock=OperationLock::acquire($this->rootPath,false);
        try {
            if ($this->recoveryPending()) throw new \RuntimeException('Recovery pending');
            $v=$this->verifyPackage($this->resolvePackage($package),$expectedSha,$signature);
            if (!$v['ok']) throw new \RuntimeException($v['error']);
            if ($v['mode']!=='dist'||$v['version']!==$this->currentVersion()) throw new \RuntimeException('Provide the signed distribution of the installed version');
            ReleaseTrust::pin($this->rootPath,$v);
            $bad=[]; $checked=0; $count=0;
            foreach ($v['manifest']['checksums'] as $relative=>$sha) {
                if (!$this->managed($relative)) continue;
                ++$checked;
                try { $matches=hash_equals($sha,OperationFiles::hash(OperationFiles::path($this->rootPath,$relative))); }
                catch (\Throwable) { $matches=false; }
                if (!$matches) { ++$count; if (count($bad)<100) $bad[]=$relative; }
            }
            // Report extra loadable files in the core and vendor trees. Custom extensions are outside this attestation.
            foreach (['core','vendor'] as $tree) foreach (OperationFiles::files($this->rootPath,$tree) as $relative) {
                if (!isset($v['manifest']['checksums'][$relative])) { ++$count; if (count($bad)<100) $bad[]=$relative; }
            }
            return ['ok'=>$count===0,'version'=>$v['version'],'sha256'=>$v['sha256'],'key_id'=>$v['key_id'],'checked'=>$checked,'mismatch_count'=>$count,'mismatches'=>$bad];
        } finally { OperationLock::release($lock); }
    }
    public function apply(array $options=[]): array
    {
        $this->cliOnly();
        foreach (['no_backup','no_maintenance','no_migrations','no_doctor'] as $flag) if (!empty($options[$flag])) throw new \RuntimeException('Safety steps cannot be disabled: '.$flag);
        $package=$this->option($options,'package')??throw new \RuntimeException('Provide --package');
        $package=$this->resolvePackage($package); $signature=$this->option($options,'signature')??$package.'.sig';
        try { $lock=OperationLock::acquire($this->rootPath,true); }
        catch (OperationBusy $e) { if (!empty($options['autonomous'])) return ['ok'=>false,'deferred'=>true,'reason'=>'operation_busy']; throw $e; }
        $dir=null; $journalled=false; $lifecycle=null;
        try {
            if (OperationLock::active($this->rootPath)||is_file($this->rootPath.'/storage/app/backup-restore.json')||is_file($this->rootPath.'/storage/app/extensions/deployment.json')) throw new \RuntimeException('Interrupted operation: recover first');
            if (!is_file($this->rootPath.'/storage/app/installed.lock')) throw new \RuntimeException('Installed forum required');
            // Authenticate before allocating a persistent transaction directory. Reverify the copied bytes below.
            $initial=$this->verifyPackage($package,$this->option($options,'sha256'),$signature);
            if (!$initial['ok']) throw new \RuntimeException($initial['error']);
            UpdatePreflight::identity($initial['manifest'],$this->currentVersion(),$this->channel(),$this->option($options,'version'));
            UpdateCompatibility::requireRuntime($initial['manifest'],$this->config);
            $this->discardAbandonedStages();
            $backup=new BackupManager($this->rootPath,$this->config);
            $autonomous=!empty($options['autonomous']);
            $space=UpdatePreflight::space($this->rootPath,$package,$initial['manifest'],$autonomous?$backup->updatePreflight():$backup->preflight());
            $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)); $dir=$this->transactionDir($id); OperationFiles::dir($dir); chmod($dir,0700);
            // Copy first, then authenticate the exact bytes that will be staged.
            OperationFiles::copy($package,$dir.'/package.zip'); OperationFiles::copy($signature,$dir.'/package.zip.sig');
            $v=$this->verifyPackage($dir.'/package.zip',$this->option($options,'sha256'));
            if (!$v['ok']) throw new \RuntimeException($v['error']);
            UpdatePreflight::identity($v['manifest'],$this->currentVersion(),$this->channel(),$this->option($options,'version'));
            if (!hash_equals($initial['sha256'],$v['sha256'])) throw new \RuntimeException('Artifact changed during staging');
            $runtime=UpdateCompatibility::requireRuntime($v['manifest'],$this->config);
            UpdatePreflight::migrations($this->rootPath,$v['manifest']['checksums']);
            $previous=$this->currentVersion();
            \CajeerEngine\Forum\Migration\ForumMigrationEngine::assertNoRunning(new \CajeerEngine\Database\DatabaseManager($this->config));
            $compatibility=(new \CajeerEngine\Extension\ExtensionRegistry($this->rootPath,$this->config))->targetCompatibility($v['version'],$v['manifest']['engine_api_version']);
            if(!$compatibility['ok'])throw new \RuntimeException('Target release incompatible with enabled extensions: '.implode(', ',array_column($compatibility['blocked'],'name')).'; resolve extension dependencies before updating');
            $this->postCheck(false,true);
            $lifecycle=ServiceLifecycle::configured();
            if ($autonomous && $lifecycle===null) return ['ok'=>false,'deferred'=>true,'reason'=>'service_policy_missing'];
            if ($lifecycle!==null) {
                try { $lifecycle->preflight($previous); }
                catch (\Throwable $e) { if ($autonomous) return ['ok'=>false,'deferred'=>true,'reason'=>'service_preflight_failed']; throw $e; }
            }
            $plan=$this->stage($dir,$v['manifest']);
            if ($autonomous) UpdatePreflight::writablePlan($this->rootPath,$plan);
            $report=['ok'=>true,'id'=>$id,'previous_version'=>$previous,'target_version'=>$v['version'],'sha256'=>$v['sha256'],'copy_plan'=>$plan,'preflight'=>['space'=>$space,'runtime'=>$runtime,'extensions'=>$compatibility,'migration_history'=>'verified','recovery'=>'ready'],'dry_run'=>!empty($options['dry_run'])];
            if ($report['dry_run']) return $report;
            ReleaseTrust::pin($this->rootPath,$v);
            if (!empty($options['autonomous'])) {
                if (!$this->config->bool('updates.operator.enabled') || !$this->config->bool('updates.web_upgrade.enabled') || ($options['from_version']??null)!==$previous || OperationLock::maintenance($this->rootPath)) return ['ok'=>false,'deferred'=>true,'reason'=>'admission_changed'];
                if (($reason=(new UpdatePolicy($this->config))->autonomousReason($previous,$v['version'],$v['security_update'],time()))!==null) return ['ok'=>false,'deferred'=>true,'reason'=>$reason];
            }
            if (!empty($options['scheduled_request']) && ($reason=(new UpdatePolicy($this->config))->requestReason('apply',$v['version'],time()))!==null) throw new \RuntimeException('Update policy: '.$reason);
            $journal=['id'=>$id,'state'=>'prepared','recovery_protocol'=>2,'previous_version'=>$previous,'target_version'=>$v['version'],'sha256'=>$v['sha256'],'release_key_id'=>$v['key_id'],'release_authorization_expires'=>$v['authorization']['expires_at']??null,'maintenance_was_enabled'=>$this->maintenanceState()['enabled'],'maintenance_before'=>is_file($this->rootPath.'/storage/app/maintenance.json')?OperationFiles::read($this->rootPath.'/storage/app/maintenance.json'):null,'backup'=>null,'child_lock'=>'child-'.bin2hex(random_bytes(8)).'.lock','at'=>gmdate(DATE_ATOM)];
            if ($lifecycle!==null) $journal['service_lifecycle']=$lifecycle->identity();
            $journal['mutation_started']=false;
            if (!empty($options['autonomous'])) $journal['authorization']=['type'=>'host_policy','mode'=>(new UpdatePolicy($this->config))->snapshot()['mode']];
            $journalled=true; $this->journal($journal);
            DatabaseBackup::useProcessLock($dir.'/'.$journal['child_lock']);
            try {
                $this->setMaintenance('Updating to '.$v['version']);
                if ($lifecycle!==null) {
                    $journal['service_phase']='stopping'; $this->journal($journal);
                    $lifecycle->stop();
                    $journal['service_phase']='stopped'; $this->journal($journal);
                }
                $journal['state']='backup'; $this->journal($journal);
                $journal['backup']=$backup->capture()['id'];
                if ($autonomous) {
                    $journal['state']='verifying_backup'; $this->journal($journal);
                    $proof=$backup->proveUpdateRecovery($journal['backup']);
                    $journal['recovery_proof']=$proof;
                    $report['recovery_proof']=$proof;
                    // The drill may exceed the permitted window. Do not replace any live files.
                    if (($reason=(new UpdatePolicy($this->config))->autonomousReason($previous,$v['version'],$v['security_update'],time()))!==null) {
                        $result=['ok'=>false,'deferred'=>true,'reason'=>$reason,'id'=>$id,'state'=>'rolled_back','backup'=>['id'=>$journal['backup']],'recovery_proof'=>$proof];
                        if ($lifecycle!==null) { $lifecycle->start($previous); $journal['service_phase']='started'; $this->journal($journal); }
                        $journal['state']='rolled_back'; $journal['deferred_reason']=$reason; $this->journal($journal);
                        $this->finish($journal,$result); return $result;
                    }
                }
                $journal['state']='backed_up'; $this->journal($journal);
                // Recovery code is copied from the old release before any replacement.
                $this->recoveryLauncher($dir,$backup->directory($journal['backup']));
                // Recheck host trust/revocation and expiry after potentially lengthy backup rehearsal.
                $fresh=ReleaseTrust::configured($this->rootPath)->verify($v['sha256'],$dir.'/package.zip.sig',$v['version'],$v['mode']);
                if ($fresh['key_id']!==$v['key_id']) throw new \RuntimeException('Release signer changed during update');
                $journal['state']='applying'; $journal['mutation_started']=true; $this->journal($journal);
                foreach ($plan['files'] as $relative) {
                    $dest=OperationFiles::path($this->rootPath,$relative);
                    $mode=$relative==='bin/cajeer'?0755:(is_file($dest)?fileperms($dest)&0777:0644);
                    OperationFiles::copy(OperationFiles::path($dir,'stage/'.$relative),$dest,$mode,is_file($dest)?fileowner($dest):null,is_file($dest)?filegroup($dest):null);
                    if (!hash_equals($v['manifest']['checksums'][$relative],OperationFiles::hash($dest))) throw new \RuntimeException('Post-copy checksum mismatch');
                }
                foreach ($plan['removed_files'] as $relative) OperationFiles::remove(OperationFiles::path($this->rootPath,$relative));
                OperationFiles::json($this->rootPath.'/release-manifest.json',$v['manifest'],0644);
                $journal['state']='migrating'; $this->journal($journal);
                $this->clearCaches(); $this->postCheck(true);
                if ($lifecycle!==null) {
                    $journal['service_phase']='starting'; $this->journal($journal);
                    $lifecycle->start($v['version']);
                    $journal['service_phase']='started'; $this->journal($journal);
                }
                $journal['state']='committed'; $this->journal($journal);
                $result=$report+['applied_files'=>$plan['files'],'removed_files'=>$plan['removed_files'],'backup'=>['id'=>$journal['backup']],'state'=>'committed'];
                $this->finish($journal,$result);
                \CajeerEngine\Observability\OperationsMonitor::marker($this->rootPath,'update');
                return $result;
            } catch (\Throwable $e) {
                // A durable terminal decision must never be converted into a destructive rollback.
                if (in_array($journal['state'],['committed','rolled_back'],true)) throw $e;
                $journal['failure']='Update failed: '.$e->getMessage();
                try {
                    if ($lifecycle!==null) {
                        $journal['service_phase']='stopping'; $this->journal($journal);
                        $lifecycle->stop(); $journal['service_phase']='stopped'; $this->journal($journal);
                    }
                    if ($journal['mutation_started'] && empty($journal['restored'])) {
                        if ($journal['backup']===null) throw new \RuntimeException('Missing recovery backup');
                        $journal['state']='recovering'; $this->journal($journal);
                        $backup->restoreCaptured($journal['backup']); $this->clearCaches(); $this->postCheck(false);
                        $journal['restored']=true; $this->journal($journal);
                    }
                    if ($lifecycle!==null) {
                        $journal['service_phase']='starting'; $this->journal($journal);
                        $lifecycle->start($previous); $journal['service_phase']='started'; $this->journal($journal);
                    }
                    $journal['state']='rolled_back'; $this->journal($journal);
                    $result=['ok'=>false,'id'=>$id,'state'=>'rolled_back','error'=>$journal['failure'],'backup'=>['id'=>$journal['backup']]];
                    $this->finish($journal,$result); \CajeerEngine\Observability\OperationsMonitor::marker($this->rootPath,'update','failure'); return $result;
                } catch (\Throwable $recovery) {
                    $journal['state']='recovery_required'; $journal['recovery_error']=$recovery->getMessage(); $this->journal($journal);
                    \CajeerEngine\Observability\OperationsMonitor::marker($this->rootPath,'update','failure');
                    $this->report(['ok'=>false,'id'=>$id,'state'=>'recovery_required','error'=>'Automatic recovery failed; maintenance remains enabled','backup'=>['id'=>$journal['backup']]]);
                    throw new \RuntimeException('Recovery required; run update:rollback under maintenance: '.$recovery->getMessage());
                }
            }
        } finally { try { if (!$journalled&&$dir!==null) $this->discardStage($dir); } finally { DatabaseBackup::useProcessLock(null); OperationLock::release($lock); } }
    }
    public function rollback(?string $backup=null, bool $confirmed=false, ?string $expectedTransaction=null): array
    {
        $this->cliOnly(); $lock=OperationLock::acquire($this->rootPath,true); $child=null;
        try {
            $active=OperationLock::active($this->rootPath);
            if ($active) {
                $j=OperationFiles::read($this->path('active.json'));
                if (($j['recovery_protocol']??null)===2) $j=RecoveryJournal::active($this->rootPath);
            }
            else {
                if (!$confirmed) throw new \RuntimeException('Rollback after a committed update discards subsequent writes; pass --yes');
                $last=OperationFiles::read($this->path('last-update.json'));
                if (($last['state']??null)!=='committed') throw new \RuntimeException('No committed update to roll back');
                $j=OperationFiles::read($this->transactionDir($last['id']).'/journal.json');
                if ($this->currentVersion()!==$j['target_version']) throw new \RuntimeException('Current version does not match rollback target');
            }
            if ($expectedTransaction!==null&&$expectedTransaction!==$j['id']) throw new \RuntimeException('Stale rollback request');
            if ($backup!==null && $backup!==($j['backup']??null)) throw new \RuntimeException('Backup must match active/latest update');
            $dir=$this->transactionDir($j['id']);
            if (isset($j['child_lock'])) { if (!preg_match('/^child-[a-f0-9]{16}\.lock$/D',$j['child_lock'])) throw new \RuntimeException('Invalid child lease'); $child=OperationLock::childGuard($dir.'/'.$j['child_lock']); }
            // A terminal transaction may have crashed between journal, report and gate cleanup.
            // Do not restore a snapshot or restart writers after the durable decision.
            if ($active && ($j['recovery_protocol']??null)===2 && in_array($j['state'],['committed','rolled_back'],true)) {
                $version=$j['state']==='committed'?$j['target_version']:$j['previous_version'];
                if ($this->currentVersion()!==$version || (isset($j['service_lifecycle']) && ($j['service_phase']??null)!=='started')) throw new \RuntimeException('Terminal recovery identity mismatch');
                $result=['ok'=>true,'id'=>$j['id'],'state'=>$j['state'],'version'=>$version,'previous_version'=>$j['previous_version'],'target_version'=>$j['target_version'],'sha256'=>$j['sha256'],'backup'=>['id'=>$j['backup']??null],'reconciled'=>true];
                $this->finish($j,$result); return $result;
            }
            // Explicit rollback of a committed update is a new destructive intent.
            // Persist it before stopping services so a later crash cannot be mistaken for commit cleanup.
            if (!$active) { $j['state']='recovering'; $j['mutation_started']=true; $j['restored']=false; }
            $j['child_lock']='child-'.bin2hex(random_bytes(8)).'.lock';
            $this->journal($j); DatabaseBackup::useProcessLock($dir.'/'.$j['child_lock']);
            $this->setMaintenance('Recovering update');
            $lifecycle=ServiceLifecycle::configured();
            if (isset($j['service_lifecycle'])) {
                if ($lifecycle===null) throw new \RuntimeException('Restore original service policy before recovery');
                $lifecycle->assertIdentity($j['service_lifecycle']);
            } elseif ($lifecycle!==null) {
                $j['service_lifecycle']=$lifecycle->identity(); $this->journal($j);
            }
            if ($lifecycle!==null) {
                $j['service_phase']='stopping'; $this->journal($j);
                $lifecycle->stop(); $j['service_phase']='stopped'; $this->journal($j);
            }
            $state=$j['state'];
            $mutated=$j['mutation_started']??!in_array($state,['prepared','backup','verifying_backup','backed_up','rolled_back'],true);
            if ($mutated && empty($j['restored'])) {
                if (empty($j['backup'])) throw new \RuntimeException('Missing recovery backup');
                $j['state']='recovering'; $this->journal($j);
                (new BackupManager($this->rootPath,$this->config))->restoreCaptured($j['backup']);
                $this->clearCaches(); $this->postCheck(false);
                $j['restored']=true; $this->journal($j);
            }
            if ($lifecycle!==null) {
                $j['service_phase']='starting'; $this->journal($j);
                $lifecycle->start($j['previous_version']); $j['service_phase']='started'; $this->journal($j);
            }
            $j['state']='rolled_back'; $this->journal($j);
            $result=['ok'=>true,'id'=>$j['id'],'state'=>'rolled_back','version'=>$j['previous_version'],'backup'=>['id'=>$j['backup']??null]];
            $this->finish($j,$result); return $result;
        } finally { DatabaseBackup::useProcessLock(null); OperationLock::release($child); OperationLock::release($lock); }
    }
    public function maintenanceState(): array
    {
        $p=$this->rootPath.'/storage/app/maintenance.json';
        $data=is_file($p)?json_decode((string)file_get_contents($p),true):[];
        return ['enabled'=>OperationLock::maintenance($this->rootPath),'file'=>$p,'update_active'=>OperationLock::active($this->rootPath)]+(is_array($data)?$data:[]);
    }
    public function enableMaintenance(string $message='Maintenance'): array { $this->setMaintenance($message); return ['ok'=>true]+$this->maintenanceState(); }
    public function disableMaintenance(): array
    {
        if (is_file($this->rootPath.'/storage/app/extensions/deployment.json')) throw new \RuntimeException('Recover extension deployment first');
        // HTTP holds a shared lease. The active marker blocks reopening after a crash.
        if (OperationLock::active($this->rootPath)||is_file($this->rootPath.'/storage/app/backup-restore.json')) throw new \RuntimeException('Cannot leave maintenance while recovery is pending');
        if(is_file($this->rootPath.'/storage/app/installed.lock')) \CajeerEngine\Forum\Migration\ForumMigrationEngine::assertNoRunning(new \CajeerEngine\Database\DatabaseManager($this->config));
        OperationFiles::remove($this->rootPath.'/storage/app/maintenance.json'); return ['ok'=>true,'enabled'=>false];
    }
    public function wizardState(): array
    {
        $last=PHP_SAPI==='cli'?$this->path('last-update.json'):$this->requestDir().'/last-report.json';
        $report=is_file($last)?OperationFiles::read($last):null;
        $activePath=PHP_SAPI==='cli'?$this->path('active.json'):$this->requestDir().'/progress.json';
        $active=OperationLock::active($this->rootPath)&&is_file($activePath)?$this->publicJournal(OperationFiles::read($activePath)):null;
        $rollback=['available'=>false];
        if (!$this->recoveryPending() && ($report['state']??null)==='committed' && ($report['target_version']??null)===$this->currentVersion() && is_string($report['id']??null) && preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$report['id'])) $rollback=['available'=>true,'transaction'=>$report['id'],'from_version'=>$report['target_version'],'to_version'=>$report['previous_version']??null];
        return ['rollback'=>$rollback,'ok'=>true,'operator_ready'=>($operator=UpdateOperator::health($this->rootPath,$this->config))['ready'],'operator_status'=>$operator,'staged_installable'=>$this->stageEligible($this->staged()),'current_version'=>$this->currentVersion(),'maintenance'=>$this->maintenanceState(),'active'=>$active,'last_report'=>$report===null?null:array_intersect_key($report,array_flip(['ok','id','state','previous_version','target_version','version'])),'installed'=>is_file($this->rootPath.'/storage/app/installed.lock'),'production_apply_enabled'=>$this->trustConfigured()];
    }
    public function history(int $limit=20): array
    {
        $this->cliOnly(); if ($limit<1||$limit>100) throw new \RuntimeException('History limit must be 1..100');
        $base=$this->path('transactions'); $items=[];
        if (is_dir($base)) foreach(new \DirectoryIterator($base) as $entry) {
            if ($entry->isDot()||$entry->isLink()||!$entry->isDir()||!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$entry->getFilename())) continue;
            $file=$entry->getPathname().'/journal.json'; if (!is_file($file)) continue;
            $j=OperationFiles::read($file); if (($j['id']??null)!==$entry->getFilename()) throw new \RuntimeException('Invalid update history journal');
            $items[$j['id']]=$this->publicJournal($j)+['backup'=>$j['backup']??null];
            krsort($items); if (count($items)>$limit) array_pop($items);
        }
        return ['ok'=>true,'items'=>array_values($items)];
    }
    private function publicJournal(array $j): array { return array_intersect_key($j,array_flip(['id','state','previous_version','target_version','sha256','release_key_id','release_authorization_expires','updated_at','service_phase'])); }
    public function staged(): array
    {
        $dir=$this->requestDir(); $file=$dir.'/staged.json';
        return is_file($file)?OperationFiles::read($file):[];
    }
    /** CLI places and verifies a signed artifact before a browser can request it. */
    public function publish(string $package, ?string $signature=null, ?string $expectedSha=null, ?string $expectedVersion=null, bool $automatic=false): array
    {
        $this->cliOnly(); $package=$this->resolvePackage($package); $lock=OperationLock::acquire($this->rootPath,true); $dir=null; $published=false; $approval=null;
        try {
            if ($this->recoveryPending()) throw new \RuntimeException('Recovery pending');
            if ($automatic) {
                $policy=new UpdatePolicy($this->config);
                if ($expectedVersion===null || $policy->stageReason($this->currentVersion(),$expectedVersion)!==null) throw new \RuntimeException('Automatic staging denied by policy');
                $approval=OperationLock::openLockFile($this->requestDir().'/approval.lock',0660);
                if (!flock($approval,LOCK_EX|LOCK_NB)) throw new \RuntimeException('Another approval is in progress');
                foreach (glob($this->requestDir().'/request-*.json')?:[] as $file) {
                    if (!in_array(OperationFiles::read($file)['state']??null,['completed','failed'],true)) throw new \RuntimeException('Approved operation takes precedence');
                }
                $prior=$this->staged();
                if ($this->stageEligible($prior) && ($prior['version']??null)===$expectedVersion && ($prior['sha256']??null)===$expectedSha) {
                    $v=$this->verifyPackage($this->path('inbox/'.$prior['id'].'/package.zip'),$expectedSha);
                    if ($v['ok'] && $v['version']===$expectedVersion) {
                        if ($policy->stageReason($this->currentVersion(),$v['version'],$v['security_update'])!==null) throw new \RuntimeException('Automatic staging denied by signed release policy');
                        UpdatePreflight::identity($v['manifest'],$this->currentVersion(),$this->channel(),$expectedVersion);
                        UpdateCompatibility::requireRuntime($v['manifest'],$this->config);
                        if (!(new \CajeerEngine\Extension\ExtensionRegistry($this->rootPath,$this->config))->targetCompatibility($v['version'],$v['manifest']['engine_api_version'])['ok']) throw new \RuntimeException('Target release incompatible with enabled extensions');
                        ReleaseTrust::pin($this->rootPath,$v);
                        (new AutonomousUpdate($this->rootPath,$this->config))->rememberStage($prior);
                        return $prior+['cached'=>true];
                    }
                }
            }
            $free=disk_free_space($this->rootPath.'/storage/app');
            if ($free!==false && $free < filesize($package)+33554432) throw new \RuntimeException('Insufficient disk space for verified inbox staging');
            $id=bin2hex(random_bytes(16)); $dir=$this->path('inbox/'.$id); OperationFiles::dir($dir); chmod($dir,0700);
            OperationFiles::copy($package,$dir.'/package.zip'); OperationFiles::copy($signature??$package.'.sig',$dir.'/package.zip.sig');
            $v=$this->verifyPackage($dir.'/package.zip',$expectedSha);
            if (!$v['ok']) throw new \RuntimeException($v['error']);
            UpdatePreflight::identity($v['manifest'],$this->currentVersion(),$this->channel(),$expectedVersion);
            if ($automatic && $policy->stageReason($this->currentVersion(),$v['version'],$v['security_update'])!==null) throw new \RuntimeException('Automatic staging denied by signed release policy');
            $runtime=UpdateCompatibility::requireRuntime($v['manifest'],$this->config);
            $extensions=(new \CajeerEngine\Extension\ExtensionRegistry($this->rootPath,$this->config))->targetCompatibility($v['version'],$v['manifest']['engine_api_version']);
            if(($automatic||isset($v['manifest']['upgrade_compatibility']))&&!$extensions['ok'])throw new \RuntimeException('Target release incompatible with enabled extensions');
            ReleaseTrust::pin($this->rootPath,$v);
            $data=['ok'=>true,'id'=>$id,'from_version'=>$this->currentVersion(),'runtime_compatibility'=>$runtime,'extension_compatibility'=>$extensions,'version'=>$v['version'],'sha256'=>$v['sha256'],'file_count'=>$v['file_count']];
            OperationFiles::json($this->requestDir().'/staged.json',$data,0644); $published=true;
            if ($automatic) (new AutonomousUpdate($this->rootPath,$this->config))->rememberStage($data);
            return $data;
        } finally { try { if (!$published&&$dir!==null) $this->discardStage($dir); } finally { OperationLock::release($approval); OperationLock::release($lock); } }
    }
    public function request(string $action, array $payload, string $actor): array
    {
        if (($payload['confirm']??null)!==true) throw new \RuntimeException('Explicit confirmation required');
        $guard=OperationLock::openLockFile($this->requestDir().'/approval.lock',0660);
        try {
            if (!flock($guard,LOCK_EX|LOCK_NB)) throw new \RuntimeException('Another approval is in progress');
            if ($this->recoveryPending()) throw new \RuntimeException('Recovery is required from CLI');
            foreach (glob($this->requestDir().'/request-*.json')?:[] as $file) {
                $prior=OperationFiles::read($file);
                if (!in_array($prior['state']??null,['completed','failed'],true)) throw new \RuntimeException('Another approved request is unresolved');
            }
            $id=bin2hex(random_bytes(16)); $now=gmdate(DATE_ATOM);
            $data=['ok'=>true,'id'=>$id,'action'=>$action,'actor'=>$actor,'requested_at'=>$now,'confirmed_at'=>$now,'state'=>'pending'];
            if ($action==='apply') {
                $stage=$this->staged();
                if (!$this->stageEligible($stage)||($stage['id']??null)!==($payload['package']??null)||($stage['sha256']??null)!==($payload['sha256']??null)) throw new \RuntimeException('Select the exact newer staged artifact and digest');
                $data['stage']=$stage['id']; $data['sha256']=$stage['sha256']; $data['version']=$stage['version'];
            } elseif ($action==='download') {
                $version=\CajeerEngine\Forum\ForumVersion::validate($payload['version']??null);
                $check=OperationFiles::read($this->path('last-check.json'));
                $when=strtotime((string)($check['checked_at']??''));
                if ($when===false||$when<time()-3600||$when>time()+30||($check['current_version']??null)!==$this->currentVersion()||($check['channel']??null)!==$this->channel()||($check['sequential']??null)!==$this->config->bool('updates.core.sequential',true)||($check['update_available']??false)!==true||!in_array($check['metadata_status']??null,['fresh','not_modified'],true)||($check['metadata_source']??null)!==GitHubEndpoints::RELEASES_URL||($check['next']['version']??null)!==$version) throw new \RuntimeException('Fresh eligible GitHub release check required');
                UpdatePreflight::identity(['version'=>$version,'edition'=>'forum','mode'=>'dist'],$this->currentVersion(),$this->channel(),$version);
                $data['version']=$version;
            } elseif ($action==='backup') { /* full backup, no arbitrary server path */ }
            elseif ($action==='rollback') { $candidate=$this->wizardState()['rollback']; if (!$candidate['available']||($payload['transaction']??null)!==$candidate['transaction']) throw new \RuntimeException('Select the exact current rollback transaction'); $data['transaction']=$candidate['transaction']; }
            else throw new \RuntimeException('Invalid operation');
            if (($reason=(new UpdatePolicy($this->config))->requestReason($action,$data['version']??null,time(),false))!==null) throw new \RuntimeException('Update policy: '.$reason);
            UpdateOperator::assertApproved($data,$id);
            OperationFiles::json($this->requestDir().'/request-'.$id.'.json',$data,0640); return $data;
        } finally { OperationLock::release($guard); }
    }
    private function recoveryPending(): bool
    {
        foreach (['storage/app/updates/active.json','storage/app/backup-restore.json','storage/app/extensions/deployment.json'] as $marker) if (file_exists(OperationFiles::path($this->rootPath,$marker))) return true;
        return false;
    }
    private function stageEligible(array $stage): bool
    {
        try {
            if (!is_string($stage['id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$stage['id'])||!is_string($stage['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$stage['sha256'])) return false;
            UpdatePreflight::identity(['version'=>$stage['version']??null,'edition'=>'forum','mode'=>'dist'],$this->currentVersion(),$this->channel(),null);
            if(isset($stage['from_version'])&&$stage['from_version']!==$this->currentVersion())return false;
            // The web UID only reads the operator's receipt. The privileged CLI
            // independently checks exact bytes and signature before application.
            if (PHP_SAPI==='cli') {
                $file=$this->path('inbox/'.$stage['id'].'/package.zip');
                if (!is_file($file)||!is_file($file.'.sig')||is_link($file.'.sig')) return false;
            }
            return true;
        } catch (\Throwable) { return false; }
    }
    public function pending(): array
    {
        $out=[]; foreach (glob($this->requestDir().'/request-*.json')?:[] as $file) { $data=OperationFiles::read($file); if (($data['state']??null)==='pending') $out[]=$data; } return $out;
    }
    /** Browser-safe, bounded status view; never return server paths or package bytes. */
    public function recentRequests(int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $items = [];
        $dir = $this->requestDir();
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot()) continue;
            $name = $entry->getFilename();
            if (!str_starts_with($name, 'request-')) continue;
            if (!preg_match('/^request-[a-f0-9]{32}\.json$/D', $name) || $entry->isLink() || !$entry->isFile()) {
                throw new \RuntimeException('Unsafe update request journal');
            }
            $record = OperationFiles::read($entry->getPathname());
            if (($record['id'] ?? '') !== substr($name, 8, 32)) throw new \RuntimeException('Request journal ID mismatch');
            $items[] = array_intersect_key($record, array_flip([
                'id', 'action', 'actor', 'state', 'version', 'requested_at', 'confirmed_at', 'started_at', 'finished_at', 'failure', 'sha256',
            ]));
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['requested_at'] ?? ''), (string) ($a['requested_at'] ?? '')) ?: strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? '')));
        return array_slice($items, 0, $limit);
    }

    /** Diagnostic only: a progress record never authorizes installation. */
    public function downloadProgress(): array
    {
        try { return array_intersect_key(OperationFiles::read($this->path('download-status.json')),array_flip(['version','state','received_bytes','total_bytes','checked_at'])); }
        catch (\Throwable) { return []; }
    }
    /** Read the signed download receipt for UI; staging will independently verify it. */
    public function downloaded(): array
    {
        $dir = $this->path('downloads');
        if (!is_dir($dir) || is_link($dir)) return [];
        $results = [];
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot() || !$entry->isDir() || $entry->isLink() ||
                !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D', $entry->getFilename())) continue;
            $receiptPath = $entry->getPathname() . '/verified.json';
            if (!is_file($receiptPath) || is_link($receiptPath)) continue;
            $receipt = OperationFiles::read($receiptPath);
            if (($receipt['format'] ?? '') !== 'cajeerengine.verified-release.v1' || ($receipt['version'] ?? null) !== $entry->getFilename() ||
                !preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['sha256'] ?? ''))) continue;
            $results[] = array_intersect_key($receipt, array_flip(['version', 'channel', 'sha256', 'size', 'downloaded_at', 'key_id']));
        }
        usort($results, static fn (array $a, array $b): int => version_compare((string) $b['version'], (string) $a['version']));
        return array_slice($results, 0, 20);
    }
    public function runRequest(string $id): array
    {
        $this->cliOnly(); if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \RuntimeException('Invalid request ID');
        $guardDir=$this->path('request-locks'); OperationFiles::dir($guardDir);
        $guard=OperationLock::childGuard($guardDir.'/'.$id.'.lock');
        try {
            $path=$this->requestDir().'/request-'.$id.'.json'; $r=OperationFiles::read($path);
            UpdateOperator::assertApproved($r,$id);
            if ($this->recoveryPending()) throw new \RuntimeException('Recovery required before executing request');
            if (($reason=$this->requestPolicyReason($r))!==null) {
                if (in_array($reason,['outside_maintenance_window','invalid_policy'],true)) return ['ok'=>true,'deferred'=>true,'reason'=>$reason];
                $r['state']='failed'; $r['failure']='Update policy: '.$reason; $r['finished_at']=gmdate(DATE_ATOM);
                OperationFiles::json($path,$r,0640); return ['ok'=>false,'reason'=>$reason];
            }
            // Persist admission before any mutating call. A process killed after
            // this point leaves a running record, never a replayable approval.
            $r['state']='running'; $r['started_at']=gmdate(DATE_ATOM); OperationFiles::json($path,$r,0640);
            try {
                if ($r['action']==='download') {
                    $download=(new GitHubReleaseDownloader($this->rootPath,$this->config))->download($r['version']);
                    $result=$this->publish($download['package'],null,$download['sha256'],$r['version']);
                } elseif ($r['action']==='apply') {
                    $result=$this->apply(['package'=>$this->path('inbox/'.$r['stage'].'/package.zip'),'sha256'=>$r['sha256'],'version'=>$r['version']??null,'scheduled_request'=>true]);
                } elseif ($r['action']==='rollback') {
                    $result=$this->rollback(null,true,$r['transaction']);
                } else $result=(new BackupManager($this->rootPath,$this->config))->create();
            } catch (\Throwable $e) {
                // A recovery marker must remain a hard barrier; do not authorize
                // another request after a partially executed update or restore.
                if (!$this->recoveryPending()) {
                    $r['state']='failed'; $r['failure']='Operation failed; inspect protected diagnostics'; $r['finished_at']=gmdate(DATE_ATOM); OperationFiles::json($path,$r,0640);
                }
                throw $e;
            }
            $r['state']=($result['ok']??false)===true?'completed':'failed'; $r['finished_at']=gmdate(DATE_ATOM);
            if (is_string($result['id']??null)) $r['result_id']=$result['id'];
            OperationFiles::json($path,$r,0640); return $result;
        } finally { OperationLock::release($guard); }
    }
    public function requestPolicyReason(array $record): ?string
    {
        $version=$record['version']??null;
        if (($record['action']??null)==='apply' && !is_string($version)) {
            $stage=$this->staged();
            if (($stage['id']??null)!==($record['stage']??null) || !is_string($stage['version']??null)) return 'stage_identity_missing';
            $version=$stage['version'];
        }
        return (new UpdatePolicy($this->config))->requestReason((string)($record['action']??''),is_string($version)?$version:null,time());
    }
    private function channel(): string { return $this->config->string('updates.core.channel','stable'); }
    private function discardAbandonedStages(): void
    {
        $base=$this->path('transactions'); if (!is_dir($base)) return;
        foreach (new \DirectoryIterator($base) as $entry) {
            if ($entry->isDot()||$entry->isLink()||!$entry->isDir()||!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$entry->getFilename())) continue;
            $dir=$entry->getPathname();
            if (!is_file($dir.'/journal.json')) $this->discardStage($dir);
        }
    }
    private function discardStage(string $dir): void
    {
        if (!is_dir($dir)||is_link($dir)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isLink()||!$entry->isDir()) { if (!unlink($entry->getPathname())) throw new \RuntimeException('Cannot remove temporary stage'); }
            elseif (!rmdir($entry->getPathname())) throw new \RuntimeException('Cannot remove temporary stage directory');
        }
        if (!rmdir($dir)) throw new \RuntimeException('Cannot remove temporary transaction');
    }
    private function stage(string $dir,array $manifest): array
    {
        $files=[]; $zip=$dir.'/package.zip';
        foreach (['tools/update-postcheck.php','tools/update-recovery.php','core/Update/UpdateManager.php'] as $required) if (!in_array($required,$manifest['files'],true)) throw new \RuntimeException('Target lacks recovery protocol');
        foreach ($manifest['files'] as $p) {
            if (!$this->managed($p)) continue;
            $dest=OperationFiles::path($dir,'stage/'.$p);
            OperationFiles::copy('phar://'.$zip.'/cajeerengine/'.$p,$dest);
            if (!hash_equals($manifest['checksums'][$p],OperationFiles::hash($dest))) throw new \RuntimeException('Staged checksum mismatch');
            OperationFiles::path($this->rootPath,$p); $files[]=$p;
        }
        $removed=$manifest['removed_files']??[];
        // Only previously shipped paths are automatically retired; local custom files are not inferred as obsolete.
        if (is_file($this->rootPath.'/release-manifest.json')) {
            $prior=OperationFiles::read($this->rootPath.'/release-manifest.json');
            if (($prior['format']??null)==='cajeerengine.release-manifest.v5'&&($prior['version']??null)===$this->currentVersion()&&($prior['mode']??null)==='dist') {
                foreach ($prior['files']??[] as $p) if (is_string($p)&&$this->managed($p)&&!in_array($p,$files,true)) $removed[]=$p;
            }
        }
        // Remove obsolete Composer files, which cannot remain loadable after dependency replacement.
        foreach (OperationFiles::files($this->rootPath,'vendor') as $p) if ($this->managed($p)&&!in_array($p,$files,true)) $removed[]=$p;
        foreach ($removed as $p) { if (!$this->managed($p)||in_array($p,$files,true)) throw new \RuntimeException('Invalid removal'); OperationFiles::path($this->rootPath,$p); }
        return ['files'=>$files,'removed_files'=>array_values(array_unique($removed))];
    }
    private function managed(string $p): bool { return ReleaseFilePolicy::allows($p,'dist')&&!in_array($p,ReleaseFilePolicy::KEEP,true); }
    private function clearCaches(): void
    {
        foreach (['storage/cache','bootstrap/cache'] as $dir) foreach (OperationFiles::files($this->rootPath,$dir) as $file) if (basename($file)!=='.gitkeep') OperationFiles::remove(OperationFiles::path($this->rootPath,$file));
    }
    private function postCheck(bool $migrate,bool $readOnly=false): void
    {
        $tool=$this->rootPath.'/tools/update-postcheck.php';
        if (!is_file($tool)) throw new \RuntimeException('Release lacks updater health check');
        DatabaseBackup::run([PHP_BINARY,$tool,$migrate?'migrate':($readOnly?'preflight':'verify')]);
    }
    private function recoveryLauncher(string $dir,string $backup): void
    {
        // Backup contains the old core and its dependencies. Recovery entrypoint remains outside replaced tree.
        $source=$this->rootPath.'/tools/update-recovery.php';
        OperationFiles::copy($source,$dir.'/recover.php');
        OperationFiles::json($dir.'/recovery.json',['root'=>$this->rootPath,'backup'=>$backup,'transaction'=>basename($dir)]);
    }
    private function journal(array $j): void { $j['updated_at']=gmdate(DATE_ATOM); OperationFiles::json($this->transactionDir($j['id']).'/journal.json',$j); OperationFiles::json($this->path('active.json'),$j); OperationFiles::json($this->requestDir().'/progress.json',$this->publicJournal($j),0644); }
    private function finish(array $j,array $report): void { $this->report($report+['previous_version'=>$j['previous_version'],'target_version'=>$j['target_version'],'sha256'=>$j['sha256']]); if (!$j['maintenance_was_enabled']) OperationFiles::remove($this->rootPath.'/storage/app/maintenance.json'); elseif (is_array($j['maintenance_before']??null)) OperationFiles::json($this->rootPath.'/storage/app/maintenance.json',$j['maintenance_before'],0644); OperationFiles::remove($this->requestDir().'/progress.json'); OperationFiles::remove($this->path('active.json')); }
    private function report(array $report): void { OperationFiles::json($this->path('last-update.json'),$report,0600); OperationFiles::json($this->requestDir().'/last-report.json',array_intersect_key($report,array_flip(['ok','id','state','previous_version','target_version','version'])),0644); }
    private function setMaintenance(string $message): void { OperationFiles::json($this->rootPath.'/storage/app/maintenance.json',['enabled'=>true,'message'=>$message,'since'=>gmdate(DATE_ATOM)],0644); }
    private function transactionDir(string $id): string { if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$id)) throw new \RuntimeException('Invalid transaction ID'); return $this->path('transactions/'.$id); }
    private function path(string $p): string { return OperationFiles::path($this->rootPath,'storage/app/updates/'.$p); }
    private function requestDir(): string { $p=OperationFiles::path($this->rootPath,'storage/app/update-requests'); if (!is_dir($p)) { OperationFiles::dir($p,0770); chmod($p,02770); } return $p; }
    private function trustConfigured(): bool { try { ReleaseTrust::configured($this->rootPath); return true; } catch (\Throwable) { return false; } }
    private function option(array $o,string $k): ?string { $v=$o[$k]??null; return is_string($v)&&trim($v)!==''?trim($v):null; }
    private function resolvePackage(string $p): string { if (str_contains($p,'://')||str_contains($p,"\0")) throw new \RuntimeException('Use local ZIP'); return str_starts_with($p,'/')?$p:$this->rootPath.'/'.$p; }
    private function currentVersion(): string { return (new \CajeerEngine\Forum\ForumVersion($this->rootPath))->current(); }
    private function cliOnly(): void { if (PHP_SAPI!=='cli') throw new \RuntimeException('Use dedicated CLI updater'); }
}
