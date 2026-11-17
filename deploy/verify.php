<?php
// Run from the trusted operator checkout, never from the candidate archive.
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class) use ($root): void {
    if (str_starts_with($class,'CajeerEngine\\')) {
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if (is_file($file)) require $file;
    }
});
try {
    if ($argc===2 && $argv[1]==='--identity') {
        $identity=new \CajeerEngine\Forum\ForumVersion(getcwd());
        echo json_encode(['edition'=>'forum','edition_version'=>$identity->current(),'upstream_version'=>$identity->upstream()],JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if (!in_array($argc,[5,6],true) || ($argc===6 && $argv[5]!=='--installed') || !preg_match('/^(?:\d+\.\d+\.\d+|1\.0\.0-rc\.1)$/D',$argv[4])) throw new RuntimeException('Usage: verify.php ZIP PUBLIC_KEY SHA256 VERSION');
    $signature=\CajeerEngine\Update\ReleaseSignature::verify($argv[1],$argv[1].'.sig',$argv[2],$argv[3]);
    $zip=new PharData($argv[1]);
    $name='cajeerengine/release-manifest.json';
    if (!isset($zip[$name]) || $zip[$name]->getSize()>16*1024*1024) throw new RuntimeException('Missing or oversized release manifest');
    $identity=json_decode($zip[$name]->getContent(),true,512,JSON_THROW_ON_ERROR);
    if (($identity['edition']??null)!=='forum' || ($identity['mode']??null)!=='dist' || !isset($identity['upstream_version'])) throw new RuntimeException('Forum Edition dist required');
    $manifest=(new \CajeerEngine\Release\ReleaseArtifactVerifier())->verify($argv[1],'dist',$argv[4]);
    $release=json_decode($zip['cajeerengine/release.json']->getContent(),true,512,JSON_THROW_ON_ERROR);
    if (($release['channel']??null)!==($argv[4]==='1.0.0-rc.1'?'rc':'stable')) throw new RuntimeException('Release channel and approved version differ');
    if ($argc===6) {
        $installed=getcwd();
        foreach ($manifest['checksums'] as $path=>$digest) {
            if (in_array($path,\CajeerEngine\Release\ReleaseFilePolicy::KEEP,true)) continue;
            $file=\CajeerEngine\Update\OperationFiles::path($installed,$path);
            if (!hash_equals($digest,\CajeerEngine\Update\OperationFiles::hash($file))) throw new RuntimeException('Installed release differs: '.$path);
        }
    }
    echo json_encode(['edition'=>'forum','edition_version'=>$manifest['version'],'upstream_version'=>$manifest['upstream_version'],'sha256'=>$signature['sha256']],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) { fwrite(STDERR,"Artifact verification failed: ".$e->getMessage()."\n"); exit(1); }
