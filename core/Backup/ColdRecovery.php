<?php

declare(strict_types=1);
namespace CajeerEngine\Backup;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Update\OperationLock;
use CajeerEngine\Forum\ForumVersion;

/** Operator-only new-host recovery. Never connects to the source database or overwrites an installation. */
final class ColdRecovery
{
    public function __construct(private readonly string $root) {}

    /** A protected sibling workspace is the durable recovery journal; publish only after all probes. */
    public function run(string $bundle,string $keyFile,string $target,string $profile,int $rto=0,int $rpo=0): array
    {
        $bundle=$this->input($bundle); $keyFile=$this->input($keyFile,true); $profile=$this->input($profile,true);
        $target=$this->destination($target); $parent=dirname($target);
        foreach ([$bundle,$keyFile,$profile] as $input) if (str_starts_with($input,$target.'/')) throw new \RuntimeException('Keep recovery inputs outside the destination');
        $work=$parent.'/.'.basename($target).'.recovery';
        if (is_link($work)) throw new \RuntimeException('Unsafe recovery workspace');
        if (!is_dir($work)) { if (!mkdir($work,0700)) throw new \RuntimeException('Cannot create recovery workspace'); F::syncDir($parent); }
        if ((fileperms($work)&0077)!==0||fileowner($work)!==posix_geteuid()) throw new \RuntimeException('Recovery workspace must be private and operator-owned');
        $lock=OperationLock::childGuard($work.'/operation.lock');
        $child=null;
        try {
            $child=OperationLock::childGuard($work.'/child.lock'); OperationLock::release($child); $child=null;
            $digest=F::hash($bundle); $fingerprint=hash('sha256',$digest.F::hash($profile).F::hash($keyFile).':'.$rto.':'.$rpo);
            $journal=$work.'/state.json';
            if (!is_file($journal)) foreach (new \FilesystemIterator($work,\FilesystemIterator::SKIP_DOTS) as $item) if (!in_array($item->getFilename(),['operation.lock','child.lock'],true)) throw new \RuntimeException('Recovery workspace contains unrelated files');
            $state=is_file($journal)?F::read($journal):['fingerprint'=>$fingerprint,'started_at'=>time(),'phase'=>'new','abandoned_databases'=>[]];
            if (($state['fingerprint']??null)!==$fingerprint) throw new \RuntimeException('Resume with the original bundle, key and profile');
            if (file_exists($target)||is_link($target)) {
                // Handles a crash after atomic publication, before the journal was committed.
                if (is_link($target)||!is_dir($target)||!is_file($target.'/storage/app/recovery-receipt.json')) throw new \RuntimeException('Destination already exists; never overwrite an installation');
                $receipt=F::read($target.'/storage/app/recovery-receipt.json');
                if (!in_array($state['phase'],['publishing','complete'],true)||($receipt['operation']??null)!==($state['operation']??null)||($receipt['bundle_sha256']??null)!==$digest) throw new \RuntimeException('Destination does not belong to this recovery');
                $state['phase']='complete'; F::json($journal,$state);
                if (is_dir($work.'/snapshot')) RecoveryBundle::cleanup($work.'/snapshot');
                return $receipt;
            }
            if (($state['phase']??null)==='complete') throw new \RuntimeException('Published destination is missing; start a new recovery');
            $state['operation']??=bin2hex(random_bytes(16)); F::json($journal,$state);
            $stage=$work.'/snapshot'; $site=$work.'/site';
            // A killed DB subprocess holds this lease independently of the parent.
            DatabaseBackup::useProcessLock($work.'/child.lock');
            putenv('BACKUP_KEY_FILE='.$keyFile);
            $key=base64_decode(trim((string)file_get_contents($keyFile)),true);
            if (!is_string($key)||strlen($key)!==32) throw new \RuntimeException('Invalid recovery key');
            try {
                if (!is_file($stage.'/manifest.mac')||!in_array($state['phase'],['verified','restoring','checking','publishing'],true)) {
                    if (is_dir($stage)) RecoveryBundle::cleanup($stage);
                    F::dir($stage); $this->space($work,(int)filesize($bundle)); RecoveryBundle::extract($bundle,$stage,$key);
                    if (!hash_equals($digest,F::hash($bundle))) throw new \RuntimeException('Bundle changed during recovery');
                }
            } finally { sodium_memzero($key); }
            $manifest=F::read($stage.'/manifest.json'); $driver=$manifest['database']['driver']??null;
            if (!in_array($driver,['sqlite','pgsql'],true)) throw new \RuntimeException('New-host recovery supports SQLite and PostgreSQL only');
            $config=new ConfigRepository($this->root); $config->set('database.default',$driver);
            // The portable verifier checks the dump and MAC, not the old host or absolute DB path.
            $config->set('database.connections.sqlite.database','storage/database/cajeer.sqlite');
            $snapshot=(new BackupManager($this->root,$config))->portableSnapshot($stage);
            $m=$snapshot['manifest']; $captured=(new \DateTimeImmutable($m['created_at']))->getTimestamp();
            if ($captured>time()+300) throw new \RuntimeException('Backup creation time is in the future');
            $age=max(0,time()-$captured);
            if ($rpo>0&&$age>$rpo) throw new \RuntimeException('Recovery point exceeds configured RPO');
            $settings=$this->profile(F::read($profile),$driver);
            $state['phase']='verified'; F::json($journal,$state);
            if (is_dir($site)) RecoveryBundle::cleanup($site);
            F::dir($site); $this->space($work,(int)filesize($bundle)); $this->restoreFiles($stage,$site,$m);
            $version=new ForumVersion($site);
            if ($version->current()!==($m['edition_version']??null)||$version->upstream()!==($m['version']??null)) throw new \RuntimeException('Recovery release identity mismatch');
            // Only now load dependencies from the authenticated snapshot (or trusted operator dist).
            if (!is_file($site.'/vendor/autoload.php')) throw new \RuntimeException('Recovery requires a complete application backup with vendor dependencies');
            if (!class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
                if (!is_file($this->root.'/vendor/autoload.php')) throw new \RuntimeException('Recovery operator requires trusted vendor dependencies');
                require_once $this->root.'/vendor/autoload.php';
            }
            if (str_contains($snapshot['environment'],'$(')) throw new \RuntimeException('Command substitution in snapshot environment is not supported');
            $original=(new \Symfony\Component\Dotenv\Dotenv())->parse($snapshot['environment'],$site.'/.env');
            if (($original['DB_DRIVER']??'pgsql')!==$driver||($original['STORAGE_DRIVER']??'local')!=='local'||(!in_array($original['QUEUE_DRIVER']??'file',['file','pgsql'],true)||!in_array($original['SCHEDULER_DRIVER']??'file',['file','pgsql'],true))) throw new \RuntimeException('Unsupported snapshot runtime; recover external storage/queues separately');
            if (!empty($original['EXTENSIONS_TRUST_DIR'])&&empty($settings['EXTENSIONS_TRUST_DIR'])) throw new \RuntimeException('Provision the original extension trust directory on the new host');
            // A previous interrupted attempt may have created a database. Never drop it; allocate another unique name.
            if (isset($state['database'])) $state['abandoned_databases'][]=$state['database'];
            unset($state['database']);
            if ($driver==='pgsql') { $state['database']='ce_dr_'.bin2hex(random_bytes(12)); $settings['DB_DATABASE']=$state['database']; }
            $state['phase']='restoring'; F::json($journal,$state);
            $settings+=['DB_DRIVER'=>$driver,'SQLITE_DATABASE'=>'storage/database/cajeer.sqlite','DB_DATABASE'=>'storage/database/cajeer.sqlite','DB_HOST'=>'127.0.0.1','DB_PORT'=>'5432','DB_USERNAME'=>'','DB_PASSWORD'=>'','DB_SSLMODE'=>'disable'];
            $settings+=['APP_ENV'=>'production','APP_DEBUG'=>'false','BACKUP_KEY_FILE'=>$keyFile,'STORAGE_DRIVER'=>'local','STORAGE_LOCAL_PATH'=>'storage/app','CACHE_DRIVER'=>'file','RATE_LIMIT_DRIVER'=>'file','FORUM_CACHE_ENABLED'=>'false','REDIS_HOST'=>'127.0.0.1','REDIS_PASSWORD'=>'','FORUM_MEDIA_S3_REPLICA'=>'false','SEARCH_DRIVER'=>$driver==='pgsql'?'pgsql':'file','UPDATE_WEB_UPGRADE_ENABLED'=>'false','UPDATE_TRUSTED_PUBLIC_KEY_FILE'=>'','EXTENSIONS_TRUST_DIR'=>'','FORUM_SMTP_HOST'=>'','FORUM_SMTP_USERNAME'=>'','FORUM_SMTP_PASSWORD'=>'','SCHEDULER_ENABLE_WEBHOOK_DISPATCH'=>'false','OBSERVABILITY_OTLP_ENDPOINT'=>'','OBSERVABILITY_OTLP_TOKEN'=>'','ADMIN_PASSWORD'=>'','FORUM_REGISTRATION'=>'false','S3_ENDPOINT'=>'','S3_BUCKET'=>'','S3_ACCESS_KEY'=>'','S3_SECRET_KEY'=>'','MEILISEARCH_HOST'=>'','MEILISEARCH_KEY'=>'','OPENSEARCH_HOST'=>''];
            // Preserve application encryption secrets, forum policy, and queue type; remove old service endpoints.
            F::write($site.'/.env',$snapshot['environment']);
            (new \CajeerEngine\Installer\InstallerEnvironment($site.'/.env'))->write($settings,$site.'/.env.example');
            F::dir($site.'/storage/database');
            $dest=new ConfigRepository($this->root); $dest->set('database.default',$driver);
            $dest->set('database.connections.sqlite.database','storage/database/cajeer.sqlite');
            $dest->set('database.connections.pgsql',['host'=>$settings['DB_HOST'],'port'=>(int)$settings['DB_PORT'],'database'=>$settings['DB_DATABASE'],'username'=>$settings['DB_USERNAME'],'password'=>$settings['DB_PASSWORD'],'sslmode'=>$settings['DB_SSLMODE']]);
            (new DatabaseBackup($site,$dest))->restoreFresh($stage.'/database.dump');
            $state['phase']='checking'; F::json($journal,$state);
            // No listeners/workers; fresh process runs restored code with only destination environment.
            $checks=$this->probe($site,$work,$keyFile);
            $elapsed=max(0,time()-(int)$state['started_at']);
            $receipt=['ok'=>true,'operation'=>$state['operation'],'id'=>$m['id'],'bundle_sha256'=>$digest,'edition_version'=>$m['edition_version'],'database'=>$driver,'database_name'=>$settings['DB_DATABASE'],'restored_files'=>count($m['files']),'application_boot_tested'=>true,'checks'=>$checks,'snapshot_age_seconds'=>$age,'restore_seconds'=>$elapsed,'rto_seconds'=>$rto?:null,'rpo_seconds'=>$rpo?:null,'rto_met'=>$rto===0||$elapsed<=$rto,'rpo_met'=>true,'maintenance'=>true,'abandoned_databases'=>$state['abandoned_databases'],'verified_at'=>gmdate(DATE_ATOM)];
            F::json($site.'/storage/app/recovery-receipt.json',$receipt);
            $state['phase']='publishing'; F::json($journal,$state);
            if (file_exists($target)||is_link($target)||!rename($site,$target)) throw new \RuntimeException('Cannot publish recovered installation');
            F::syncDir($parent); $state['phase']='complete'; F::json($journal,$state);
            RecoveryBundle::cleanup($stage);
            return $receipt;
        } finally { DatabaseBackup::useProcessLock(null); OperationLock::release($child); OperationLock::release($lock); }
    }

    /** Copy an already authenticated inventory; never preserve numeric UIDs from a different host. */
    public function restoreFiles(string $stage,string $site,array $manifest): void
    {
        foreach ($manifest['files'] as $relative=>$info) {
            $dest=F::path($site,$relative); F::copy(F::path($stage,'files/'.$relative),$dest,$info['mode']&0755);
            if (!hash_equals($info['sha256'],F::hash($dest))) throw new \RuntimeException('Recovered file checksum mismatch');
        }
        foreach (['storage/app','storage/database','storage/logs','storage/cache','storage/tmp','storage/queue','bootstrap/cache','public/uploads','public/extensions'] as $dir) F::dir(F::path($site,$dir));
        if (!is_file($site.'/storage/app/installed.lock')) throw new \RuntimeException('Snapshot is not an installed forum');
        F::json($site.'/storage/app/maintenance.json',['enabled'=>true,'message'=>'Recovered installation: validate before opening','since'=>gmdate(DATE_ATOM)],0644);
    }

    private function space(string $directory,int $bytes): void
    {
        $free=disk_free_space($directory);
        if ($bytes<=0||$free===false||$free<$bytes+67108864) throw new \RuntimeException('Insufficient recovery workspace space');
    }

    private function profile(array $p,string $driver): array
    {
        $allowed=['APP_URL','DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD','DB_SSLMODE','EXTENSIONS_TRUST_DIR','UPDATE_TRUSTED_PUBLIC_KEY_FILE'];
        foreach ($p as $k=>$v) if (!in_array($k,$allowed,true)||!is_string($v)||preg_match('/[\x00-\x1f\x7f]/',$v)) throw new \RuntimeException('Invalid destination profile field');
        $url=parse_url($p['APP_URL']??'');
        if (!filter_var($p['APP_URL']??'',FILTER_VALIDATE_URL)||!is_array($url)||!in_array($url['scheme']??'', ['http','https'],true)||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||!in_array($url['path']??'', ['','/'],true)) throw new \RuntimeException('Specify the destination APP_URL origin');
        if ($url['scheme']!=='https'&&!in_array($url['host'],['127.0.0.1','localhost','[::1]'],true)) throw new \RuntimeException('Public recovery origin requires HTTPS');
        if ($driver==='pgsql') {
            foreach (['DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD','DB_SSLMODE'] as $k) if (!isset($p[$k])||$p[$k]==='') throw new \RuntimeException('Destination PostgreSQL credentials required');
            if (!preg_match('/^[A-Za-z0-9_.-]+$/D',$p['DB_HOST'])||!ctype_digit($p['DB_PORT'])||(int)$p['DB_PORT']<1||(int)$p['DB_PORT']>65535||!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D',$p['DB_USERNAME'])||!in_array($p['DB_SSLMODE'],['disable','verify-full'],true)||($p['DB_SSLMODE']==='disable'&&$p['DB_HOST']!=='127.0.0.1')) throw new \RuntimeException('Invalid destination PostgreSQL connection (remote requires verify-full)');
        } else foreach (['DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD','DB_SSLMODE'] as $k) if (isset($p[$k])) throw new \RuntimeException('SQLite destination does not accept PostgreSQL settings');
        foreach (['EXTENSIONS_TRUST_DIR','UPDATE_TRUSTED_PUBLIC_KEY_FILE'] as $k) if (isset($p[$k])&&$p[$k]!=='') {
            if (!str_starts_with($p[$k],'/')||realpath($p[$k])!==$p[$k]||($k==='EXTENSIONS_TRUST_DIR'?!is_dir($p[$k]):!is_file($p[$k]))) throw new \RuntimeException('Provision external trust material');
        }
        return $p;
    }
    private function input(string $path,bool $private=false): string
    {
        if (!str_starts_with($path,'/')||realpath($path)!==$path||!is_file($path)||is_link($path)||($private&&(fileperms($path)&0077)!==0)) throw new \RuntimeException('Use an absolute regular recovery input (secrets mode 0600)');
        return $path;
    }
    private function destination(string $path): string
    {
        $parent=dirname($path); $base=basename($path);
        if (!str_starts_with($path,'/')||realpath($parent)!==$parent||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,100}$/D',$base)||!is_dir($parent)||fileowner($parent)!==posix_geteuid()||(fileperms($parent)&0022)!==0||str_starts_with($path,rtrim($this->root,'/').'/')||$path===$this->root) throw new \RuntimeException('Use a new destination under a private operator-owned directory outside the tool installation');
        return $path;
    }
    private function probe(string $site,string $work,string $key): array
    {
        $script=$this->root.'/tools/recovery-probe.php';
        // Do not inherit .env overrides, old DB credentials, service tokens or proxy configuration.
        $env=['PATH'=>getenv('PATH')?:'/usr/bin:/bin','HOME'=>getenv('HOME')?:'/nonexistent','BACKUP_KEY_FILE'=>$key];
        if (getenv('BACKUP_SQLITE_PYTHON')) $env['BACKUP_SQLITE_PYTHON']=getenv('BACKUP_SQLITE_PYTHON');
        $out=$work.'/probe.json'; $err=$work.'/probe.log';
        $process=proc_open(['flock','--exclusive','--nonblock',$work.'/child.lock',PHP_BINARY,$script,$site],[0=>['file','/dev/null','r'],1=>['file',$out,'w'],2=>['file',$err,'w']],$pipes,$site,$env,['bypass_shell'=>true]);
        if (!is_resource($process)||proc_close($process)!==0) throw new \RuntimeException('Restored application probe failed; inspect private recovery workspace');
        $result=F::read($out); if (($result['ok']??false)!==true) throw new \RuntimeException('Restored application probe did not pass');
        return $result;
    }
}
