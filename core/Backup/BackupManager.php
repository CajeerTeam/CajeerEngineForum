<?php

declare(strict_types=1);
namespace CajeerEngine\Backup;

use CajeerEngine\Release\ReleaseFilePolicy;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Update\OperationLock;

/** Local recovery sets: database + exact file inventory + encrypted environment. */
final class BackupManager
{
    private DatabaseBackup $db;
    private string $base;
    private const DATA_ROOTS=['storage/app','storage/queue','public/uploads','public/extensions','modules','plugins','themes'];
    private const OMIT=['storage/app/backups','storage/app/updates','storage/app/update-requests','storage/app/support','storage/app/reports','storage/app/routing.lock','storage/app/media.lock','storage/app/operations.lock','storage/queue/locks','storage/app/webhook-locks','storage/app/maintenance.json','storage/app/backup-restore.json','storage/app/import-staging','storage/app/import-requests','storage/app/import-reports','storage/app/extensions/deployment.json','storage/app/extensions/registry.lock'];
    public function __construct(private readonly string $root, private readonly ConfigRepository $config)
    {
        $this->db=new DatabaseBackup($root,$config);
        $this->base=F::path($root,'storage/app/backups/production');
    }
    /** Validate recovery prerequisites and estimate the bytes captured by this exact backup policy. */
    public function preflight(): int
    {
        $this->assertStorage(); $key=$this->secretKey(); sodium_memzero($key);
        $this->db->assertRecoveryReady();
        $bytes=$this->db->estimatedBytes();
        foreach (array_unique(array_merge($this->dataFiles(),$this->codeFiles())) as $relative) {
            $size=filesize(F::path($this->root,$relative));
            if ($size===false) throw new \RuntimeException('Cannot estimate recovery file');
            $bytes+=$size+4096; // directory entries, manifest and filesystem allocation
        }
        return $bytes;
    }
    /** The automatic updater must have enough space for a fresh snapshot and its isolated rehearsal. */
    public function updatePreflight(): int
    {
        $this->db->assertDrillReady();
        $bytes=$this->preflight();
        $env=F::path($this->root,'.env');
        if (!is_file($env)||!is_readable($env)) throw new \RuntimeException('Original environment is required for automatic recovery');
        $envSize=filesize($env);
        if ($envSize===false||$envSize>PHP_INT_MAX-$bytes-4096) throw new \RuntimeException('Cannot estimate encrypted environment');
        $bytes+=$envSize+4096;
        $this->privateBase();
        $offsite=$this->updateOffsitePolicy();
        if ($offsite!==null) new OffsiteBackup($this->root,$offsite);
        // Snapshot + restored copy + optional encrypted transfer spool. Include allocation headroom.
        $multiplier=$offsite===null?2:4;
        if ($bytes>intdiv(PHP_INT_MAX-67108864,$multiplier)) throw new \RuntimeException('Recovery size exceeds supported estimate');
        $need=$bytes*$multiplier+67108864;
        $free=disk_free_space($this->base);
        if ($free===false||$free<$need) throw new \RuntimeException('Insufficient backup filesystem capacity for recovery rehearsal');
        $this->db->assertRestoreSpace();
        $paths=array_unique(array_merge($this->dataFiles(),$this->codeFiles(),['.env']));
        $checked=[];
        foreach ($paths as $relative) {
            $path=F::path($this->root,$relative);
            for ($entry=$path;$entry!==$this->root && !isset($checked[$entry]);$entry=dirname($entry)) {
                $this->assertRestorePermissions($entry); $checked[$entry]=true;
            }
        }
        return $bytes*$multiplier;
    }
    private function assertRestorePermissions(string $path): void
    {
        if (!is_writable(dirname($path))||!is_executable(dirname($path))) throw new \RuntimeException('Recovery destination directory is not writable');
        if (function_exists('posix_geteuid')&&posix_geteuid()!==0 && (fileowner($path)!==posix_geteuid()||!in_array(filegroup($path),array_merge([posix_getegid()],posix_getgroups()),true))) throw new \RuntimeException('Operator cannot preserve recovery ownership');
    }
    private function updateOffsitePolicy(): ?BackupPolicy
    {
        $path=getenv('UPDATE_BACKUP_OFFSITE_POLICY_FILE');
        return $path===false||$path===''?null:BackupPolicy::load($this->root,$path);
    }
    /** Caller owns the exclusive update lease. Never restore into the live installation. */
    public function proveUpdateRecovery(string $id): array
    {
        $this->db->assertDrillReady();
        $verified=$this->verify($id); $source=$this->directory($id);
        $m=F::read($source.'/manifest.json');
        if (($m['edition_version']??null)!==(new \CajeerEngine\Support\ProjectInfo($this->root))->editionVersion() || ($m['env']??null)===null) throw new \RuntimeException('Recovery requires the current release and encrypted environment');
        foreach (array_merge(array_keys($m['files']),array_keys($m['dirs']),['.env']) as $relative) $this->assertRestorePermissions(F::path($this->root,$relative));
        $this->db->assertRestoreSpace();
        $work=F::path($this->base,'rehearsal-'.bin2hex(random_bytes(16)));
        if (!mkdir($work,0700)) throw new \RuntimeException('Cannot reserve private recovery rehearsal');
        $started=microtime(true);
        try {
            $restored=$work.'/restored'; F::dir($restored);
            foreach ($m['files'] as $relative=>$info) {
                $dest=F::path($restored,$relative);
                // Keep copies private; do not reproduce executable or world-readable permissions.
                F::copy(F::path($source,'files/'.$relative),$dest);
                if (!hash_equals($info['sha256'],F::hash($dest))) throw new \RuntimeException('Recovery file readback failed');
            }
            $env=$this->decryptEnv($source);
            try {
                F::write($restored.'/.env',$env);
                if (!hash_equals(hash('sha256',$env),F::hash($restored.'/.env'))) throw new \RuntimeException('Recovery environment readback failed');
            } finally { sodium_memzero($env); }
            $db=$this->db->drill($source.'/database.dump',$work);
            if (($db['tables_read']??0)<1 || !(($db['restore_verified']??false)||($db['integrity_verified']??false))) throw new \RuntimeException('Recovered database has no verified application tables');
            $offsite=null;
            if (($policy=$this->updateOffsitePolicy())!==null) {
                $bundle=$work.'/snapshot.cebackup'; $key=$this->secretKey();
                try { RecoveryBundle::export($source,$bundle,$key); }
                finally { sodium_memzero($key); }
                $sha=F::hash($bundle); $size=filesize($bundle);
                if ($size===false||$size<1) throw new \RuntimeException('Invalid encrypted recovery bundle');
                (new OffsiteBackup($this->root,$policy))->publish($id,$sha,$bundle,$size);
                $offsite=['verified'=>true,'sha256'=>$sha,'bytes'=>$size,'destination_identity'=>$policy->identity()];
            }
            // Reauthenticate the original snapshot after rehearsal, not a separate backup.
            $again=$this->verify($id);
            if (!hash_equals($verified['manifest_sha256'],$again['manifest_sha256'])) throw new \RuntimeException('Recovery snapshot changed during rehearsal');
            return ['ok'=>true,'id'=>$id,'manifest_sha256'=>$verified['manifest_sha256'],'database'=>$db,'files_restored'=>count($m['files']),'environment_restored'=>true,'offsite'=>$offsite,'verified_at'=>gmdate(DATE_ATOM),'seconds'=>round(microtime(true)-$started,3),'application_boot_tested'=>false];
        } finally { RecoveryBundle::cleanup($work); }
    }
    public function create(): array
    {
        // A live request may briefly hold a shared activity lease even after
        // the gateway is drained. A manual backup should wait for that request
        // to finish, but must never take the snapshot concurrently or hang
        // indefinitely on an orphaned lease.
        $deadline=microtime(true)+10.0;
        do {
            try { $lock=OperationLock::acquire($this->root,true); break; }
            catch (\RuntimeException $e) {
                if ($e->getMessage()!=='Another operation is running; retry after drain' || microtime(true)>=$deadline) throw $e;
                usleep(100000);
            }
        } while (true);
        try { if (OperationLock::active($this->root)||is_file($this->root.'/storage/app/backup-restore.json')) throw new \RuntimeException('Recover the active operation first'); return $this->capture(); }
        finally { OperationLock::release($lock); }
    }
    /** Caller holds exclusive operation lock. */
    public function capture(?string $automation=null, ?string $reservedId=null): array
    {
        if ($automation!==null&&!preg_match('/^[a-f0-9]{64}$/D',$automation)) throw new \RuntimeException('Invalid backup automation identity');
        $this->assertStorage();
        $key=$this->secretKey(); sodium_memzero($key);
        $this->privateBase();
        $id=$reservedId??gmdate('Ymd-His').'-'.bin2hex(random_bytes(8));
        if ($reservedId!==null&&($automation===null||!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$id))) throw new \RuntimeException('Invalid reserved backup ID');
        $dir=$this->directory($id);
        if (!mkdir($dir,0700)) throw new \RuntimeException('Cannot reserve new backup directory');
        F::syncDir($this->base);
        $manifest=['format'=>'cajeerengine.backup.v2','id'=>$id,'version'=>$this->version(),'edition_version'=>(new \CajeerEngine\Support\ProjectInfo($this->root))->editionVersion(),'created_at'=>gmdate(DATE_ATOM),'database'=>$this->db->identity(),'database_permissions'=>$this->db->permissions(),'files'=>[],'dirs'=>[],'env'=>null,'env_permissions'=>null];
        if ($automation!==null) $manifest['automation']=$automation;
        try {
            $this->db->create($dir.'/database.dump');
            $manifest['database_sha256']=F::hash($dir.'/database.dump');
            foreach (array_unique(array_merge($this->dataFiles(),$this->codeFiles())) as $relative) {
                $from=F::path($this->root,$relative); $to=F::path($dir,'files/'.$relative);
                F::copy($from,$to);
                $manifest['files'][$relative]=['sha256'=>F::hash($to)]+$this->permissions($from);
                for ($parent=dirname($relative);$parent!=='.';$parent=dirname($parent)) if (!isset($manifest['dirs'][$parent])) $manifest['dirs'][$parent]=$this->permissions(F::path($this->root,$parent),true);
            }
            $env=F::path($this->root,'.env');
            if (is_file($env)) {
                $key=$this->secretKey();
                try { $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $cipher=$nonce.sodium_crypto_secretbox((string)file_get_contents($env),$nonce,$key); F::write($dir.'/environment.enc',$cipher); }
                finally { sodium_memzero($key); }
                $manifest['env']=F::hash($dir.'/environment.enc'); $manifest['env_permissions']=$this->permissions($env);
            }
            ksort($manifest['files']); F::json($dir.'/manifest.json',$manifest);
            $key=$this->secretKey(); try { F::write($dir.'/manifest.mac',hash_hmac('sha256',(string)file_get_contents($dir.'/manifest.json'),$key)); } finally { sodium_memzero($key); }
            $this->verify($id);
            \CajeerEngine\Observability\OperationsMonitor::marker($this->root,'backup');
            return ['ok'=>true,'id'=>$id,'target'=>$dir,'sha256'=>F::hash($dir.'/manifest.json'),'files'=>count($manifest['files']),'database'=>$manifest['database']['driver'],'version'=>$manifest['version'],'edition_version'=>$manifest['edition_version'],'created_at'=>$manifest['created_at']];
        } catch (\Throwable $e) { F::json($dir.'/incomplete.json',['error'=>'Backup failed','at'=>gmdate(DATE_ATOM)]); throw $e; }
    }
    public function list(): array
    {
        $items=[]; if (is_dir($this->base)) foreach (new \DirectoryIterator($this->base) as $entry) {
            if ($entry->isDot()||$entry->isLink()||!$entry->isDir()) continue;
            $id=$entry->getFilename(); if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$id)) continue;
            try { $m=F::read($entry->getPathname().'/manifest.json'); $items[]=['id'=>$id,'version'=>$m['version'],'created_at'=>$m['created_at'],'complete'=>!is_file($entry->getPathname().'/incomplete.json')]; } catch (\Throwable) { $items[]=['id'=>$id,'complete'=>false]; }
        }
        usort($items,static fn($a,$b)=>strcmp($b['id'],$a['id'])); return ['ok'=>true,'backups'=>$items];
    }
    public function verify(string $id): array
    {
        return $this->verifyDirectory($id,$this->directory($id));
    }
    private function verifyDirectory(string $id,string $dir,bool $portable=false): array
    {
        F::hash($dir.'/manifest.json'); F::hash($dir.'/manifest.mac'); $key=$this->secretKey();
        try { $mac=hash_hmac('sha256',(string)file_get_contents($dir.'/manifest.json'),$key); if (!is_file($dir.'/manifest.mac')||!hash_equals($mac,trim((string)file_get_contents($dir.'/manifest.mac')))) throw new \RuntimeException('Backup manifest authentication failed'); } finally { sodium_memzero($key); }
        $m=F::read($dir.'/manifest.json');
        if (($m['format']??null)!=='cajeerengine.backup.v2'||($m['id']??null)!==$id||!is_array($m['files']??null)||is_file($dir.'/incomplete.json')) throw new \RuntimeException('Incomplete or invalid recovery set');
        if (isset($m['automation'])&&(!is_string($m['automation'])||!preg_match('/^[a-f0-9]{64}$/D',$m['automation']))) throw new \RuntimeException('Invalid authenticated automation identity');
        if ((!$portable && ($m['database']??null)!==$this->db->identity())||($portable && ($m['database']['driver']??null)!==$this->db->identity()['driver'])||!hash_equals((string)($m['database_sha256']??''),F::hash($dir.'/database.dump'))) throw new \RuntimeException('Database backup identity/checksum mismatch');
        $this->db->verify($dir.'/database.dump');
        foreach ($m['files'] as $relative=>$info) {
            if (!$this->dataAllowed($relative)&&!$this->codeAllowed($relative)) throw new \RuntimeException('Forbidden backup path');
            if (!is_array($info)||!is_int($info['mode']??null)||!hash_equals((string)($info['sha256']??''),F::hash(F::path($dir,'files/'.$relative)))) throw new \RuntimeException('Backup file checksum mismatch: '.$relative);
        }
        $parents=[];
        foreach (array_keys($m['files']) as $relative) for ($p=dirname($relative);$p!=='.';$p=dirname($p)) $parents[$p]=true;
        foreach ($m['dirs']??[] as $relative=>$info) { F::path($this->root,$relative); if (!isset($parents[$relative])) throw new \RuntimeException('Invalid backup directory'); }
        $actual=F::files($dir,'files'); $expected=array_map(static fn($p)=>'files/'.$p,array_keys($m['files'])); sort($expected);
        if ($actual!==$expected) throw new \RuntimeException('Unexpected backup files');
        if (($m['env']??null)!==null) { if (!hash_equals((string)$m['env'],F::hash($dir.'/environment.enc'))) throw new \RuntimeException('Encrypted environment checksum mismatch'); $this->decryptEnv($dir); } elseif (file_exists($dir.'/environment.enc')) throw new \RuntimeException('Unexpected encrypted environment');
        return ['ok'=>true,'id'=>$id,'version'=>$m['version'],'files'=>count($m['files']),'manifest_sha256'=>F::hash($dir.'/manifest.json'),'automation'=>$m['automation']??null,'created_at'=>$m['created_at']??null,'edition_version'=>$m['edition_version']??null];
    }
    /** Offline recovery only: authenticate the snapshot independently of its old host/name. */
    public function portableSnapshot(string $directory): array
    {
        $m=F::read($directory.'/manifest.json'); $id=(string)($m['id']??'');
        $this->directory($id);
        $this->verifyDirectory($id,$directory,true);
        if (($m['env']??null)===null) throw new \RuntimeException('Recovery requires the original environment');
        return ['manifest'=>$m,'environment'=>$this->decryptEnv($directory)];
    }
    /** Publish a verified encrypted copy to a separately mounted/off-host directory. */
    public function exportBundle(string $id,string $target): array
    {
        $target=$this->externalPath($target,false);
        $lock=OperationLock::acquire($this->root,false);
        try {
            $verified=$this->verify($id); $key=$this->secretKey();
            try { RecoveryBundle::export($this->directory($id),$target,$key); }
            finally { sodium_memzero($key); }
            return $verified+['bundle'=>$target,'bundle_sha256'=>F::hash($target),'bytes'=>filesize($target)];
        } finally { OperationLock::release($lock); }
    }
    /** Caller holds shared/exclusive operation lock. Only the automation spool is writable. */
    public function exportManaged(string $id,string $identity): array
    {
        $dir=F::path($this->root,'storage/app/backups/automation'); F::dir($dir);
        $target=F::path($dir,$id.'.cebackup');
        if ($this->verify($id)['automation']!==$identity) throw new \RuntimeException('Recovery set does not belong to this automation');
        $key=$this->secretKey();
        try { RecoveryBundle::export($this->directory($id),$target,$key); }
        finally { sodium_memzero($key); }
        return ['sha256'=>F::hash($target),'bytes'=>filesize($target)];
    }
    /** Import is non-destructive; nothing becomes visible until authentication and verification pass. */
    public function importBundle(string $source): array
    {
        $source=$this->externalPath($source,true);
        $lock=OperationLock::acquire($this->root,true); $stage=null;
        try {
            $this->privateBase(); $stage=$this->base.'/.import-'.bin2hex(random_bytes(16)); F::dir($stage);
            $key=$this->secretKey();
            try { RecoveryBundle::extract($source,$stage,$key); }
            finally { sodium_memzero($key); }
            $id=(string)(F::read($stage.'/manifest.json')['id']??''); $target=$this->directory($id);
            $result=$this->verifyDirectory($id,$stage);
            if (file_exists($target)) {
                $existing=$this->verify($id);
                if ($existing['manifest_sha256']!==$result['manifest_sha256']) throw new \RuntimeException('Backup ID already exists with different contents');
                return $result+['already_present'=>true];
            }
            if (!rename($stage,$target)) throw new \RuntimeException('Cannot publish imported backup');
            $stage=null; F::syncDir($this->base);
            return $result+['already_present'=>false];
        } finally { try { if ($stage!==null) RecoveryBundle::cleanup($stage); } finally { OperationLock::release($lock); } }
    }
    /** Actual restore into a new private workspace and, for PostgreSQL, a generated scratch database. */
    public function drill(string $source,string $workspace,int $rto=0,int $rpo=0): array
    {
        $this->assertStorage();
        $source=$this->externalPath($source,true); $workspace=$this->externalPath($workspace,false);
        $started=microtime(true); $lock=OperationLock::acquire($this->root,false);
        $created=false;
        try {
            if (!mkdir($workspace,0700)) throw new \RuntimeException('Cannot create isolated drill workspace');
            $created=true; $stage=$workspace.'/snapshot'; F::dir($stage);
            $key=$this->secretKey();
            try { RecoveryBundle::extract($source,$stage,$key); }
            finally { sodium_memzero($key); }
            $m=F::read($stage.'/manifest.json'); $id=(string)($m['id']??''); $this->directory($id);
            $this->verifyDirectory($id,$stage);
            $restored=$workspace.'/restored'; F::dir($restored);
            foreach ($m['files'] as $relative=>$info) {
                $dest=F::path($restored,$relative); F::copy(F::path($stage,'files/'.$relative),$dest,$info['mode']&0777);
                if (!hash_equals($info['sha256'],F::hash($dest))) throw new \RuntimeException('Drill file verification failed');
            }
            if ($m['env']!==null) { $env=$this->decryptEnv($stage); F::write($restored.'/.env',$env); if (file_get_contents($restored.'/.env')!==$env) throw new \RuntimeException('Drill environment verification failed'); }
            $db=$this->db->drill($stage.'/database.dump',$workspace);
            $seconds=microtime(true)-$started; $age=max(0,time()-(new \DateTimeImmutable($m['created_at']))->getTimestamp());
            return ['ok'=>($rto===0||$seconds<=$rto)&&($rpo===0||$age<=$rpo),'id'=>$id,'version'=>$m['version'],'verified_at'=>gmdate(DATE_ATOM),'restored_files'=>count($m['files']),'environment_restored'=>$m['env']!==null,'database'=>$db,'restore_seconds'=>round($seconds,3),'snapshot_age_seconds'=>$age,'rto_seconds'=>$rto?:null,'rpo_seconds'=>$rpo?:null,'application_boot_tested'=>false];
        } finally { try { if ($created) RecoveryBundle::cleanup($workspace); } finally { OperationLock::release($lock); } }
    }
    private function externalPath(string $path,bool $existing): string
    {
        if (!str_starts_with($path,'/')||preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|[\x00-\x1f]~',$path)||str_contains($path,'\\')||is_link($path)) throw new \RuntimeException('Use an absolute external recovery path');
        $parent=realpath(dirname($path)); $root=realpath($this->root);
        if ($parent===false||$root===false||($parent===$root||str_starts_with($parent,$root.'/'))) throw new \RuntimeException('Recovery path must be outside the installation');
        $target=$parent.'/'.basename($path);
        if ($existing ? !is_file($target) : file_exists($target)||is_link($target)) throw new \RuntimeException('Recovery path missing or already exists');
        return $target;
    }
    public function restore(string $id): array
    {
        $lock=OperationLock::acquire($this->root,true); $child=null;
        try {
            if (OperationLock::active($this->root)) throw new \RuntimeException('Use update:rollback for active recovery');
            $this->verify($id);
            $marker=F::path($this->root,'storage/app/backup-restore.json');
            if (is_file($marker)) {
                $old=F::read($marker); if (($old['id']??null)!==$id) throw new \RuntimeException('Resume the interrupted restore with its original backup ID');
                if (!preg_match('/^restore-[a-f0-9]{16}\.lock$/D',(string)($old['child_lock']??''))) throw new \RuntimeException('Invalid restore lease');
                $child=OperationLock::childGuard($this->directory($id).'/'.$old['child_lock']);
            }
            $lease='restore-'.bin2hex(random_bytes(8)).'.lock';
            F::json($marker,['id'=>$id,'state'=>'restoring','child_lock'=>$lease,'at'=>gmdate(DATE_ATOM)],0640);
            DatabaseBackup::useProcessLock($this->directory($id).'/'.$lease);
            $maintenance=F::path($this->root,'storage/app/maintenance.json');
            F::json($maintenance,['enabled'=>true,'message'=>'Backup restore in progress','since'=>gmdate(DATE_ATOM)],0644);
            // Keep maintenance enabled on success too: operator must validate before reopening.
            $result=$this->restoreCaptured($id);
            DatabaseBackup::run([PHP_BINARY,$this->root.'/tools/update-postcheck.php','verify']);
            F::remove($marker);
            return $result;
        } finally { DatabaseBackup::useProcessLock(null); OperationLock::release($child); OperationLock::release($lock); }
    }
    /** Caller holds exclusive lock. Verify entire set before deleting or replacing anything. */
    public function restoreCaptured(string $id): array
    {
        $this->assertStorage(); $this->verify($id); $dir=$this->directory($id); $m=F::read($dir.'/manifest.json');
        $env=$m['env']!==null?$this->decryptEnv($dir):null;
        $current=array_unique(array_merge($this->dataFiles(),$this->codeFiles()));
        foreach ($m['files'] as $relative=>$info) F::path($this->root,$relative); // preflight symlinks
        $this->db->restore($dir.'/database.dump',$m['database'],$m['database_permissions']??[]);
        $dirs=$m['dirs']??[]; uksort($dirs,static fn($a,$b)=>substr_count($a,'/')<=>substr_count($b,'/'));
        foreach ($dirs as $relative=>$info) { $dest=F::path($this->root,$relative); F::dir($dest,$info['mode']); F::permissions($dest,$info['mode'],$info['uid'],$info['gid']); }
        foreach ($current as $relative) if (!array_key_exists($relative,$m['files'])) F::remove(F::path($this->root,$relative));
        foreach ($m['files'] as $relative=>$info) F::copy(F::path($dir,'files/'.$relative),F::path($this->root,$relative),$info['mode']&0777,$info['uid'],$info['gid']);
        if ($env!==null) F::write(F::path($this->root,'.env'),$env,$m['env_permissions']['mode'],$m['env_permissions']['uid'],$m['env_permissions']['gid']);
        else F::remove(F::path($this->root,'.env'));
        foreach ($m['files'] as $relative=>$info) if (!hash_equals($info['sha256'],F::hash(F::path($this->root,$relative)))) throw new \RuntimeException('Restored file verification failed');
        return ['ok'=>true,'id'=>$id,'version'=>$m['version'],'restored_files'=>array_keys($m['files']),'maintenance'=>true];
    }
    public function directory(string $id): string
    {
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$id)) throw new \RuntimeException('Invalid backup ID');
        return F::path($this->base,$id);
    }
    private function privateBase(): void { F::dir($this->base); if (!chmod($this->base,0700)) throw new \RuntimeException('Cannot protect backup directory'); }
    private function assertStorage(): void
    {
        if ($this->config->string('storage.default','local')!=='local'||!in_array($this->config->string('storage.disks.local.root','storage/app'),['storage/app',$this->root.'/storage/app'],true)) throw new \RuntimeException('External storage requires an independent consistent snapshot; automatic recovery supports default local storage only');
        if (!in_array($this->config->string('queue.default','file'),['file','pgsql','postgres','postgresql','database'],true)) throw new \RuntimeException('Drain/migrate external queues to a supported local or PostgreSQL queue before automatic recovery');
    }
    private function dataAllowed(string $p): bool
    {
        foreach (self::OMIT as $omit) if ($p===$omit||str_starts_with($p,$omit.'/')) return false;
        if ($this->db->identity()['driver']==='sqlite') {
            $db=$this->db->identity()['database'];
            foreach (['','-wal','-shm','-journal'] as $suffix) if ($this->root.'/'.$p===$db.$suffix) return false;
        }
        foreach (self::DATA_ROOTS as $root) if ($p===$root||str_starts_with($p,$root.'/')) return true;
        return false;
    }
    private function dataFiles(): array { $out=[]; foreach (self::DATA_ROOTS as $r) $out=array_merge($out,F::files($this->root,$r,fn($p)=>$this->dataAllowed($p))); return $out; }
    private function codeAllowed(string $p): bool { return $p==='release-manifest.json'||(ReleaseFilePolicy::allows($p,'dist')&&!$this->dataAllowed($p)&&!in_array($p,ReleaseFilePolicy::KEEP,true)); }
    private function codeFiles(): array
    {
        $out=[]; foreach (new \DirectoryIterator($this->root) as $entry) {
            if ($entry->isDot()) continue; $p=$entry->getFilename();
            if ($entry->isDir() && !ReleaseFilePolicy::traverses($p,'dist')) continue;
            if (!$entry->isDir()&&!$this->codeAllowed($p)) continue;
            $out=array_merge($out,F::files($this->root,$p,fn($path)=>$this->codeAllowed($path)||ReleaseFilePolicy::traverses($path,'dist')));
        }
        return array_values(array_filter($out,fn($p)=>$this->codeAllowed($p)));
    }
    private function secretKey(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) throw new \RuntimeException('ext-sodium required');
        $path=(string)getenv('BACKUP_KEY_FILE');
        if ($path===''||!str_starts_with($path,'/')||!is_file($path)||is_link($path)||(fileperms($path)&0077)!==0) throw new \RuntimeException('Provision BACKUP_KEY_FILE outside web root with mode 0600');
        if (str_starts_with(realpath($path)?:$path,rtrim($this->root,'/').'/')) throw new \RuntimeException('Backup key must be outside the project');
        $key=base64_decode(trim((string)file_get_contents($path)),true);
        if (!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new \RuntimeException('Invalid backup encryption key');
        return $key;
    }
    private function decryptEnv(string $dir): string
    {
        $key=$this->secretKey();
        try { $cipher=(string)file_get_contents($dir.'/environment.enc'); $plain=sodium_crypto_secretbox_open(substr($cipher,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($cipher,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$key); if ($plain===false) throw new \RuntimeException('Cannot decrypt environment backup'); return $plain; }
        finally { sodium_memzero($key); }
    }
    private function permissions(string $p,bool $dir=false): array { return ['mode'=>fileperms($p)&($dir?02777:0777),'uid'=>fileowner($p),'gid'=>filegroup($p)]; }
    private function version(): string { return trim((string)file_get_contents($this->root.'/VERSION')); }
}
