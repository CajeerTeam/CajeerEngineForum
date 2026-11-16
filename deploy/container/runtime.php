<?php
declare(strict_types=1);
// Readiness and immutable image/volume identity checks. No credentials are printed.
$root='/srv/forum';
try {
    $mode=$argv[1]??'';
    if ($mode==='identity') {
        $image=json_decode(file_get_contents('/opt/ce-image/release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
        $live=json_decode(file_get_contents($root.'/release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
        if ($image!==$live || ($image['edition']??'')!=='forum' || ($image['mode']??'')!=='dist') throw new RuntimeException('Release volume does not match image; use a controlled upgrade');
        foreach ($image['checksums'] as $path=>$hash) {
            // Empty runtime markers may be replaced by volumes.
            if (str_ends_with($path,'/.gitkeep')) continue;
            $file=$root.'/'.$path;
            if (!is_file($file)||is_link($file)||!hash_equals($hash,hash_file('sha256',$file))) throw new RuntimeException('Release file mismatch: '.$path);
        }
        echo "Release volume verified\n"; exit(0);
    }
    if ($mode==='loop-health') {
        // A successful batch remains healthy while the next bounded batch runs.
        // The old fixed 420-second age falsely marked valid 600-second jobs stale.
        $max=(string)(getenv('CE_LOOP_TIMEOUT_SECONDS')?:'600');
        if(!ctype_digit($max)||(int)$max<30||(int)$max>3600)exit(1);
        $time=@filemtime('/tmp/ce-loop-health');
        exit($time!==false && time()-$time<((int)$max+65) ? 0:1);
    }
    if ($mode!=='ready') throw new RuntimeException('Unknown mode');
    require $root.'/vendor/autoload.php';
    \CajeerEngine\Console\OperationsCli::loadEnv($root);
    if (!(new \CajeerEngine\Observability\OperationsMonitor($root))->readiness()['ok']) exit(1);
    // A CLI health check alone would not detect a stopped FPM master.
    $socket=@fsockopen('127.0.0.1',9000,$errno,$error,1);
    if (!$socket) exit(1);
    fclose($socket); exit(0);
} catch (Throwable $e) { fwrite(STDERR,'Container '.$mode.' failed: '.$e::class."\n"); exit(1); }
