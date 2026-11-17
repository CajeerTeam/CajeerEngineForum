<?php
declare(strict_types=1);
// Dedicated FastCGI target, never a public application route. Nginx runs as a
// different user and must not be granted read access to private operation state.
function forumContainerGate(string $root, ?callable $freeSpace=null, ?string $temporary=null): int
{
    $app=$root.'/storage/app';
    if (!is_file($app.'/installed.lock')) return 403;
    // Return 503 via Nginx auth_request when atomic writes cannot safely proceed.
    // The readiness route uses the same gate; neither cache hits nor static
    // assets may advertise an otherwise unwritable installation as healthy.
    $space=$freeSpace??static fn(string $directory): float|false => @disk_free_space($directory);
    foreach ([$root.'/storage'=>64 * 1048576, $temporary??sys_get_temp_dir()=>16 * 1048576] as $directory=>$minimum) {
        $free=$space($directory);
        if ($free===false || $free<$minimum) return 403;
    }
    foreach (['maintenance.json','updates/active.json','backup-restore.json','extensions/deployment.json'] as $state) {
        if (is_file($app.'/'.$state)) return 403;
    }
    $path=$app.'/operations.lock';
    if (is_file($path)) {
        $lock=@fopen($path,'r');
        if ($lock===false) return 403;
        try { if (!flock($lock,LOCK_SH|LOCK_NB)) return 403; }
        finally { fclose($lock); }
    }
    return 204;
}
if (PHP_SAPI!=='cli') {
    http_response_code(forumContainerGate('/srv/forum'));
    header('Cache-Control: no-store');
}
