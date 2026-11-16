<?php
declare(strict_types=1);
// Dedicated FastCGI target, never a public application route. Nginx runs as a
// different user and must not be granted read access to private operation state.
function forumContainerGate(string $root): int
{
    $app=$root.'/storage/app';
    if (!is_file($app.'/installed.lock')) return 403;
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
