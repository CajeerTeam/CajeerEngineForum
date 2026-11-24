<?php
declare(strict_types=1);
// The host uses the previously installed, immutable image to verify and recover.
$root='/srv/forum'; $old='/opt/ce-image';
require $old.'/vendor/autoload.php';
if (!class_exists(\CajeerEngine\Update\UpdatePreflight::class)) require '/opt/ce-update/UpdatePreflight.php';
spl_autoload_register(static function(string $c)use($old):void {
    if(str_starts_with($c,'CajeerEngine\\')) { $p=$old.'/core/'.str_replace('\\','/',substr($c,13)).'.php'; if(is_file($p))require $p; }
});
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Update\ReleaseSignature;
use CajeerEngine\Release\ReleaseArtifactVerifier;
use CajeerEngine\Forum\ForumVersion;
use CajeerEngine\Backup\DatabaseBackup;
function cli(string ...$args):void { global $root; DatabaseBackup::run([PHP_BINARY,$root.'/bin/cajeer',...$args]); }
function identity(string $version):void { global $root; if((new ForumVersion($root))->current()!==$version)throw new RuntimeException('Live version differs'); }
try {
    $mode=$argv[1]??''; $target=ForumVersion::validate($argv[2]??null); $sha=$argv[3]??'';
    if(!preg_match('/^[a-f0-9]{64}$/D',$sha)|| (str_contains($target,'-') && $target!=='1.0.0-rc.1'))throw new RuntimeException('Stable or explicit 1.0.0-rc.1 target required');
    $previous=(new ForumVersion($old))->current();
    if(version_compare($target,$previous,'<='))throw new RuntimeException('Target must be newer');
    $active=$root.'/storage/app/updates/active.json';$last=$root.'/storage/app/updates/last-update.json';
    $maintenance=$root.'/storage/app/maintenance.json';
    $package='/opt/ce-update/release.zip';
    if($mode==='verify') {
        identity($previous);
        DatabaseBackup::run([PHP_BINARY,'/opt/ce-container/runtime.php','identity']);
        if(\CajeerEngine\Update\OperationLock::maintenance($root))throw new RuntimeException('Resolve existing maintenance/recovery first');
        foreach (['ReleaseSignature','ReleaseTrust'] as $validator) {
            $file='/opt/ce-update/'.$validator.'.php';
            if(!is_file($file)||is_link($file))throw new RuntimeException('Trusted signature verifier unavailable');
            require_once $file;
        }
        $signature=ReleaseSignature::verify($package,$package.'.sig','/opt/ce-release.pub',$sha);
        \CajeerEngine\Update\ReleaseTrust::identity($signature,$target,'dist');
        // Only after the immutable old publisher key verifies the signed ZIP
        // may the trusted, locally provisioned operator validator be loaded.
        // The installed 0.8.x verifier rejects legitimate newer release paths.
        foreach (['ReleaseFilePolicy','ReleaseDependencyInventory','ReleaseArtifactVerifier'] as $validator) {
            $file='/opt/ce-update/'.$validator.'.php';
            if(!is_file($file)||is_link($file))throw new RuntimeException('Trusted operator verifier unavailable');
            require_once $file;
        }
        $manifest=(new ReleaseArtifactVerifier())->verify($package,'dist',$target);
        if(($manifest['edition']??null)!=='forum')throw new RuntimeException('Forum Edition required');
        $zip=new PharData($package); $release=json_decode($zip['cajeerengine/release.json']->getContent(),true,512,JSON_THROW_ON_ERROR);
        if(($release['engine_api_version']??null)!==($manifest['engine_api_version']??null)||($release['engine_api_version']??null)!==json_decode(file_get_contents($old.'/release.json'),true,512,JSON_THROW_ON_ERROR)['engine_api_version'])throw new RuntimeException('Engine API changes require a separate operator qualification');
        if(($release['channel']??null)!==($target==='1.0.0-rc.1'?'rc':'stable'))throw new RuntimeException('Signed target release channel differs');
        \CajeerEngine\Update\UpdatePreflight::migrations($root,$manifest['checksums']);
        $env=(new \CajeerEngine\Installer\InstallerEnvironment($root.'/.env'))->read();
        if(!in_array($env['QUEUE_DRIVER']??'',['pgsql','postgres','postgresql','database'],true))throw new RuntimeException('Redis queue recovery is not supported; use a PostgreSQL queue profile');
        \CajeerEngine\Console\OperationsCli::loadEnv($root);
        $config=new \CajeerEngine\Runtime\ConfigRepository($root);
        // 0.8.2 lacks the expanded recovery preflight. The immutable old
        // runtime still validates database recovery prerequisites; estimate its
        // actual persisted data before staging the signed target.
        $backup=new \CajeerEngine\Backup\BackupManager($root,$config);
        if (method_exists($backup,'preflight')) $bytes=$backup->preflight();
        else {
            (new DatabaseBackup($root,$config))->assertRecoveryReady();
            $db=new \CajeerEngine\Database\DatabaseManager($config);
            $bytes=(int)$db->connection()->query('SELECT pg_database_size(current_database())')->fetchColumn();
            foreach (['storage/app','storage/queue','public/uploads','public/extensions','plugins','modules','themes','core','vendor','config','migrations','templates','public/forum-assets','public/admin'] as $dir) {
                foreach (F::files($root,$dir,static fn($p)=>!preg_match('~^storage/app/(?:backups|updates|update-requests|reports|support)(?:/|$)~',$p)) as $p) $bytes+=(int)filesize($root.'/'.$p)+4096;
            }
        }
        \CajeerEngine\Update\UpdatePreflight::space($root,$package,$manifest,$bytes);
        // Deep preflight is real, but the historical 0.8.x command uses the
        // invalid pg_am.am column on PostgreSQL. Keep the non-FTS ecosystem
        // gate and independently perform the same database, integrity and FTS
        // checks using the audited operator implementation. No check is skipped.
        cli('forum:ecosystem:check','--target='.$target,'--json');
        $db=new \CajeerEngine\Database\DatabaseManager($config);
        if (!(new \CajeerEngine\Forum\ForumDatabaseCheck($db))->check()['ok']) throw new RuntimeException('Forum schema check failed');
        if (!(new \CajeerEngine\Forum\ForumIntegrityCheck($db))->check()['ok']) throw new RuntimeException('Forum integrity check failed');
        $searchValidator='/opt/ce-update/ForumSearchMaintenance.php';
        if(!is_file($searchValidator)||is_link($searchValidator))throw new RuntimeException('Trusted search verifier unavailable');
        require_once $searchValidator;
        if (!(new \CajeerEngine\Forum\Service\ForumSearchMaintenance($db))->check()['ok']) throw new RuntimeException('Forum search index check failed');
        echo "Approved target verified using installed publisher key\n"; exit(0);
    }
    if($mode==='apply') {
        identity($previous);
        cli('maintenance:on','--message=Container release update');
        // The selected old image's historical postcheck has a PostgreSQL 17+
        // catalog typo. Apply a scoped in-memory PHP class compatibility layer
        // to child processes. No installed source, release or backup is patched.
        if(!is_file('/opt/ce-update/compat/zz-ce-forum-compat.ini')||!is_file('/opt/ce-update/compat/prepend.php')||!is_file('/opt/ce-update/OperationsMonitor.php')||!is_file('/opt/ce-update/ForumSearchMaintenance.php'))throw new RuntimeException('Trusted compatibility layer missing');
        putenv('PHP_INI_SCAN_DIR=:/opt/ce-update/compat');
        if($target==='1.0.0-rc.1')putenv('UPDATE_CHANNEL=rc'); // Only the explicit signed RC operator target.
        cli('update:apply','--package='.$package,'--sha256='.$sha,'--version='.$target);
        identity($target);
        echo "Container code and database updated; traffic remains stopped\n"; exit(0);
    }
    if($mode==='recover') {
        if(!is_file('/opt/ce-update/compat/zz-ce-forum-compat.ini')||!is_file('/opt/ce-update/compat/prepend.php')||!is_file('/opt/ce-update/OperationsMonitor.php')||!is_file('/opt/ce-update/ForumSearchMaintenance.php'))throw new RuntimeException('Trusted compatibility layer missing');
        putenv('PHP_INI_SCAN_DIR=:/opt/ce-update/compat');
        if(is_file($active)) {
            $j=F::read($active);
            if(($j['target_version']??null)!==$target||($j['previous_version']??null)!==$previous||($j['sha256']??null)!==$sha||!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$j['id']??''))throw new RuntimeException('Active transaction is not this deployment');
            $dir=$root.'/storage/app/updates/transactions/'.$j['id'];
            if(is_file($dir.'/recovery.json')) {
                // Never execute a launcher from partially replaced live code.
                F::copy($old.'/tools/update-recovery.php',$dir.'/recover.php');
                DatabaseBackup::run([PHP_BINARY,$dir.'/recover.php']);
            } else cli('update:rollback');
        } elseif((new ForumVersion($root))->current()===$target) {
            $j=F::read($last);
            if(($j['state']??null)!=='committed'||($j['target_version']??null)!==$target||($j['previous_version']??null)!==$previous||($j['sha256']??null)!==$sha)throw new RuntimeException('Committed transaction is not this deployment');
            cli('update:rollback','--yes');
        }
        identity($previous);
        DatabaseBackup::run([PHP_BINARY,'/opt/ce-container/runtime.php','identity']);
        echo "Original release recovered and verified\n"; exit(0);
    }
    if($mode==='open') {
        // Caller has durably selected activation; no automatic rollback after this point.
        cli('maintenance:off'); echo "Maintenance released for selected release\n"; exit(0);
    }
    throw new RuntimeException('Unknown operator action');
} catch(Throwable $e) { fwrite(STDERR,'Container update failed: '.$e->getMessage()."\n"); exit(1); }
