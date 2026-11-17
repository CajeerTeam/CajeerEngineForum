<?php
declare(strict_types=1);
namespace CajeerEngine\Backup;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Update\OperationLock;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\Migration\ForumMigrationEngine;

/** Durable schedule, encrypted transfer retry and retention over managed recovery sets only. */
final class AutomatedBackup
{
    private string $base;
    private BackupManager $manager;
    private array $state;
    private string $phase='preflight';
    public function __construct(private readonly string $root,private readonly ConfigRepository $config,private readonly BackupPolicy $policy)
    {
        $this->base=F::path($root,'storage/app/backups/automation');
        $this->manager=new BackupManager($root,$config);
    }
    public static function status(string $root): array
    {
        $p=F::path($root,'storage/logs/observability/backup-automation.json');
        if (!is_file($p)) return ['ok'=>false,'status'=>'never','last_local'=>0,'last_offsite'=>0,'pending'=>0];
        $r=F::read($p);$now=time();
        $r['stale']=$now-(int)($r['checked_at']??0)>3600||$now-(int)($r['last_offsite']??0)>(int)($r['interval_seconds']??86400)+900;
        $r['ok']=($r['ok']??false)===true&&!$r['stale'];return $r;
    }
    private function save(): void { F::json($this->base.'/state.json',$this->state); }
    private function load(): void
    {
        $p=$this->base.'/state.json';
        $state=is_file($p)?F::read($p):['format'=>1,'destination'=>$this->policy->identity(),'records'=>[]];
        if (($state['format']??null)!==1||($state['destination']??null)!==$this->policy->identity()||!is_array($state['records']??null)) throw new \RuntimeException('Managed backup destination changed or state invalid; preserve the old catalog and use a new installation namespace only after operator review');
        foreach ($state['records'] as $id=>$r) {
            if (!is_string($id)||!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$id)||!is_array($r)||!is_int($r['created_at']??null)||$r['created_at']<1||!in_array($r['state']??null,['pending','verified','retiring','retired'],true)) throw new \RuntimeException('Invalid managed backup catalog');
            if (isset($r['sha256'])||$r['state']!=='pending') {
                if (!is_string($r['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['sha256'])||!is_int($r['bytes']??null)||$r['bytes']<1) throw new \RuntimeException('Invalid managed backup digest');
            }
        }
        $this->state=$state;
    }
    private function report(string $status): array
    {
        $local=0;$remote=0;$pending=0;$managed=0;$latest=null;$latestOffsite=null;
        foreach ($this->state['records']??[] as $id=>$r) {
            if ($r['state']==='retired') continue;
            ++$managed;
            if ($r['state']==='pending') ++$pending;
            if ($r['state']==='retiring') continue;
            if ($r['created_at']>=$local) {$local=$r['created_at'];$latest=$id;}
            if ($r['state']==='verified'&&$r['created_at']>=$remote) {$remote=$r['created_at'];$latestOffsite=$id;}
        }
        $recent=[];foreach (array_slice(array_reverse($this->state['records']??[],true),0,10,true) as $id=>$r) $recent[]=['id'=>$id,'state'=>$r['state'],'created_at'=>$r['created_at'],'verified_at'=>$r['verified_at']??null,'bytes'=>$r['bytes']??null,'edition_version'=>$r['edition_version']??null];
        $now=time();$fresh=$remote>0&&$now-$remote<=$this->policy->data['interval_seconds']+900;
        $result=['ok'=>$status==='success'&&$pending===0&&$fresh,'status'=>$status,'phase'=>$this->phase,'checked_at'=>$now,'last_local'=>$local,'last_offsite'=>$remote,'latest_local_id'=>$latest,'latest_offsite_id'=>$latestOffsite,'pending'=>$pending,'managed'=>$managed,'interval_seconds'=>$this->policy->data['interval_seconds'],'recent'=>$recent];
        F::json(F::path($this->root,'storage/logs/observability/backup-automation.json'),$result,0640);
        return $result;
    }
    public function run(bool $force=false): array
    {
        F::dir($this->base); if (!chmod($this->base,0700)) throw new \RuntimeException('Cannot protect backup automation catalog');
        $lockPath=F::path($this->base,'automation.lock');$lock=fopen($lockPath,'c+');
        if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) { if (is_resource($lock)) fclose($lock); throw new \RuntimeException('Another automated backup cycle is running'); }
        $activity=null;
        try {
            $this->load();
            foreach (new \DirectoryIterator($this->base) as $entry) if (preg_match('/^\.(?:dr-[a-f0-9]{32}|tmp-[a-f0-9]{24})$/D',$entry->getFilename())) F::remove($entry->getPathname());
            // Validate a mounted destination before capture, but keep local protection working if it is down.
            $destination=null; $destinationFailure=null;
            try {$destination=new OffsiteBackup($this->root,$this->policy);} catch (\Throwable $e) {$destinationFailure=$e;}
            $captureFailure=null;
            $last=0;foreach ($this->state['records'] as $r) $last=max($last,$r['created_at']);
            if ($force||$last===0||time()-$last>=$this->policy->data['interval_seconds']) {
                $this->phase='capture';
                try {
                    $activity=$this->captureLock();
                    if (!is_file($this->root.'/storage/app/installed.lock')&&!is_file($this->root.'/storage/installed.lock')) throw new \RuntimeException('Install the forum before enabling automated backup');
                    if (OperationLock::maintenance($this->root)) throw new \RuntimeException('Maintenance/recovery active; scheduled backup deferred');
                    $this->assertNoImport();
                    $estimate=$this->manager->preflight();$free=disk_free_space($this->base);
                    // Local recovery set plus a streaming encrypted spool. Never prune to make room for a failed capture.
                    if ($free===false||$free<2*$estimate+$this->policy->data['min_free_bytes']) throw new \RuntimeException('Insufficient local capacity for recovery set and encrypted transfer');
                    $created=$this->manager->capture($this->policy->identity()); $id=$created['id'];
                    $this->state['records'][$id]=['state'=>'pending','created_at'=>strtotime($created['created_at']),'edition_version'=>$created['edition_version']];$this->save();
                } catch (\Throwable $e) { $captureFailure=$e; }
                finally {OperationLock::release($activity);$activity=null;}
            }
            // A failed due capture must not starve an older durable transfer (especially under disk pressure).
            if ($captureFailure!==null&&!array_filter($this->state['records'],static fn($r)=>$r['state']==='pending')) throw $captureFailure;
            $this->phase='offsite';
            if ($destination===null) throw $destinationFailure;
            // Shared lock excludes update/restore/other destructive operators, but normal requests/workers continue.
            $activity=OperationLock::acquire($this->root,false);
            if (OperationLock::active($this->root)||is_file($this->root.'/storage/app/backup-restore.json')||is_file($this->root.'/storage/app/extensions/deployment.json')) throw new \RuntimeException('Recovery active; defer off-site transfer');
            // At most two transfers each tick: newest first, then oldest outstanding. A failed attempt never rotates.
            $pending=array_keys(array_filter($this->state['records'],static fn($r)=>$r['state']==='pending'));
            if ($pending) foreach (array_unique([end($pending),reset($pending)]) as $id) $this->transfer($destination,$id);
            if ($captureFailure!==null) {$this->phase='capture';throw $captureFailure;}
            // Do not delete anything while a copy is awaiting verified off-site publication.
            if (!array_filter($this->state['records'],static fn($r)=>$r['state']==='pending')) {
                $this->phase='retention';$this->rotate($destination);
            }
            $this->phase='idle';return $this->report('success');
        } catch (\Throwable $e) {
            // Preserve all pending work, report only a bounded phase/status (not credentials or private locations).
            if (isset($this->state)) $this->report('failed');
            else { $previous=self::status($this->root); $previous['ok']=false;$previous['status']='failed';$previous['phase']='catalog';$previous['checked_at']=time();F::json(F::path($this->root,'storage/logs/observability/backup-automation.json'),$previous,0640); }
            throw $e;
        } finally {OperationLock::release($activity);flock($lock,LOCK_UN);fclose($lock);}
    }
    private function captureLock(): mixed
    {
        $deadline=microtime(true)+$this->policy->data['lock_wait_seconds'];
        do {try {return OperationLock::acquire($this->root,true);} catch (\RuntimeException $e) {if (microtime(true)>=$deadline) throw $e; usleep(250000);}} while (true);
    }
    private function assertNoImport(): void
    {
        $driver=$this->config->string('database.default','pgsql');
        if ($driver==='sqlite'&&!in_array('sqlite',\PDO::getAvailableDrivers(),true)) {
            // The existing SQLite recovery fallback uses an explicitly provisioned Python interpreter.
            $python=(string)getenv('BACKUP_SQLITE_PYTHON');
            if (!str_starts_with($python,'/')||!is_executable($python)) throw new \RuntimeException('PDO SQLite or configured backup Python required');
            $db=new DatabaseBackup($this->root,$this->config);
            DatabaseBackup::run([$python,'-I','-c', 'import sqlite3,sys,pathlib; c=sqlite3.connect(pathlib.Path(sys.argv[1]).resolve().as_uri()+"?mode=ro",uri=True); sys.exit(1 if c.execute("SELECT 1 FROM ce_forum_import_jobs WHERE state<>\'complete\' LIMIT 1").fetchone() else 0)', $db->identity()['database']]);
        } else {
            // A stable installation must have the import ledger. Query it
            // directly; never infer safety from a cached schema probe.
            // SQLite backup and import fences must inspect the *same* file. An
            // unrelated process cwd, DB_DATABASE override or missing ledger
            // must never silently validate a different empty SQLite database.
            if ($driver==='sqlite') {
                $file=(new DatabaseBackup($this->root,$this->config))->identity()['database'];
                if (!is_file($file)||is_link($file)) throw new \RuntimeException('Configured SQLite backup database missing');
                $db=new \PDO('sqlite:file:'.$file.'?mode=ro',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
                $db->exec('PRAGMA query_only=ON');
                if (!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='ce_forum_import_jobs'")->fetchColumn())
                    throw new \RuntimeException('Forum import ledger missing; refuse to certify backup capture');
                $row=$db->query("SELECT id FROM ce_forum_import_jobs WHERE state<>'complete' LIMIT 1")->fetchColumn();
            } else {
                $db=new DatabaseManager($this->config);
                $row=$db->connection()->query("SELECT id FROM ce_forum_import_jobs WHERE state<>'complete' LIMIT 1")->fetchColumn();
            }
            if ($row!==false) throw new \RuntimeException('An incomplete forum import exists; resume it or restore its verified backup');
        }
    }
    private function transfer(OffsiteBackup $dest,string $id): void
    {
        $r=$this->state['records'][$id];$spool=F::path($this->base,$id.'.cebackup');
        if (!isset($r['sha256'])||!is_file($spool)||F::hash($spool)!==$r['sha256']||filesize($spool)!==$r['bytes']) {
            // A crash after export but before catalog commit leaves only an untrusted temporary spool.
            F::remove($spool);
            $r=array_replace($r,$this->manager->exportManaged($id,$this->policy->identity())); $this->state['records'][$id]=$r; $this->save();
        }
        $dest->publish($id,$r['sha256'],$spool,$r['bytes']);
        $r['state']='verified';$r['verified_at']=time();$this->state['records'][$id]=$r;$this->save();
        F::remove($spool);
    }
    private function verifyManaged(string $id): void
    {
        if ($this->manager->verify($id)['automation']!==$this->policy->identity()) throw new \RuntimeException('Recovery set does not belong to this automation');
    }
    private function rotate(OffsiteBackup $dest): void
    {
        $keep=$this->policy->keep($this->state['records'],time());$candidates=[];$latest=null;
        foreach ($this->state['records'] as $id=>$r) {
            if ($r['state']==='verified') { $latest=$id; if (!isset($keep[$id])) $candidates[]=$id; F::remove(F::path($this->base,$id.'.cebackup')); }
            elseif ($r['state']==='retiring') $candidates[]=$id;
        }
        if (!$candidates) return;
        if ($latest===null) throw new \RuntimeException('No protected verified backup; rotation refused');
        // Verify both newest copies now; a stale receipt alone must never authorize deletion.
        $this->verifyManaged($latest); $r=$this->state['records'][$latest];$dest->verify($latest,$r['sha256'],$r['bytes']);
        foreach (array_slice($candidates,0,10) as $id) {
            $r=$this->state['records'][$id];
            if ($r['state']==='verified') {
                // Do not retire a local set unless its own off-site copy still exists and matches.
                $this->verifyManaged($id);$dest->verify($id,$r['sha256'],$r['bytes']);
                $r['state']='retiring';$r['delete_remote']=$this->policy->data['prune_offsite'];$this->state['records'][$id]=$r;$this->save();
            }
            $dir=$this->manager->directory($id);$trash=F::path($this->base,'retired-'.$id);
            if (is_dir($dir)) {
                $this->verifyManaged($id); // Includes authenticated provenance; never prune an updater/manual set.
                if (($r['delete_remote']??false)===true) $dest->delete($id,$r['sha256']);
                if (file_exists($trash)||!rename($dir,$trash)) throw new \RuntimeException('Cannot retire managed backup');
                F::syncDir(dirname($dir));F::syncDir($this->base);
            }
            RecoveryBundle::cleanup($trash); F::syncDir($this->base);
            $r['state']='retired';$r['retired_at']=time();$this->state['records'][$id]=$r;$this->save();
        }
    }
}
