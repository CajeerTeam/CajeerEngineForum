<?php
/** Install this file outside the replaceable application tree, root-owned.
 * No application classes or Composer are loaded before crash recovery selection. */
declare(strict_types=1);
function privateRecord(string $path): array {
    clearstatcache(true,$path); $s=@lstat($path);
    if (!$s || ($s['mode']&0170000)!==0100000 || ($s['mode']&0077)!==0 || $s['uid']!==posix_geteuid() || $s['nlink']!==1 || $s['size']>4*1024*1024 || realpath($path)!==$path) throw new RuntimeException('Unsafe recovery record');
    $r=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($r)) throw new RuntimeException('Invalid recovery record'); return $r;
}
function locked(string $path) {
    if (realpath($path)!==$path || !is_file($path)) throw new RuntimeException('Missing recovery lease');
    $h=fopen($path,'r+'); $s=lstat($path); $f=fstat($h);
    if (!$s || !$f || ($s['mode']&0170000)!==0100000 || ($s['mode']&0002)!==0 || $s['nlink']!==1 || $s['ino']!==$f['ino'] || $s['dev']!==$f['dev']) { fclose($h); throw new RuntimeException('Unsafe recovery lease'); }
    if (!flock($h,LOCK_EX|LOCK_NB)) { fclose($h); return null; } return $h;
}
function persist(string $path,array $r): void {
    $tmp=dirname($path).'/.supervisor-'.bin2hex(random_bytes(12)); $h=fopen($tmp,'xb');
    try { $data=json_encode($r,JSON_THROW_ON_ERROR)."\n"; if (!chmod($tmp,0600)||fwrite($h,$data)!==strlen($data)||!fflush($h)||!fsync($h)) throw new RuntimeException('Cannot persist recovery budget'); fclose($h); $h=null; if (!rename($tmp,$path)) throw new RuntimeException('Cannot publish recovery budget'); $d=fopen(dirname($path),'r'); try { if (!fsync($d)) throw new RuntimeException('Cannot sync recovery budget'); } finally { fclose($d); } }
    finally { if (is_resource($h)) fclose($h); if (is_file($tmp)) unlink($tmp); }
}
function launch(array $argv): int {
    $p=proc_open($argv,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($p)) throw new RuntimeException('Cannot start recovery process'); return proc_close($p);
}
try {
    if (PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux'||posix_geteuid()===0) throw new RuntimeException('Dedicated non-root operator required');
    $root=$argv[1]??'';
    if ($root===''||realpath($root)!==$root||!is_dir($root)) throw new RuntimeException('Canonical application root required');
    $active=$root.'/storage/app/updates/active.json';
    if (!file_exists($active)&&!is_link($active)) exit(launch([PHP_BINARY,$root.'/bin/cajeer','update:operator:once']));
    $lease=locked($root.'/storage/app/operations.lock'); if ($lease===null) exit(0);
    $child=null; $guard=null;
    try {
        if (!file_exists($active)&&!is_link($active)) exit(0);
        $a=privateRecord($active); $id=$a['id']??null;
        if (!is_string($id)||!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$id)) throw new RuntimeException('Invalid recovery transaction');
        $dir=$root.'/storage/app/updates/transactions/'.$id;
        if (realpath($dir)!==$dir||!is_dir($dir)||fileowner($dir)!==posix_geteuid()||(fileperms($dir)&0077)!==0) throw new RuntimeException('Unsafe transaction directory');
        $j=privateRecord($dir.'/journal.json');
        foreach (['id','previous_version','target_version','sha256','recovery_protocol'] as $key) if (!isset($j[$key],$a[$key])||$j[$key]!==$a[$key]) throw new RuntimeException('Recovery journal mismatch');
        if ($j['recovery_protocol']!==2) throw new RuntimeException('Legacy transaction requires manual recovery');
        if (!in_array($j['state']??null,['prepared','backup','verifying_backup','backed_up','applying','migrating','recovering','recovery_required','committed','rolled_back'],true)||!is_bool($j['mutation_started']??null)) throw new RuntimeException('Invalid recovery phase');
        // An inherited database subprocess may still own the original child lease.
        $name=$j['child_lock']??''; if (!preg_match('/^child-[a-f0-9]{16}\.lock$/D',$name)) throw new RuntimeException('Invalid child lease');
        if (file_exists($dir.'/'.$name)||is_link($dir.'/'.$name)) { $child=locked($dir.'/'.$name); if ($child===null) exit(0); }
        if (is_link($dir.'/supervisor.lock')) throw new RuntimeException('Unsafe supervisor lock');
        $guard=@fopen($dir.'/supervisor.lock','x+'); if (is_resource($guard)) { chmod($dir.'/supervisor.lock',0600); if (!flock($guard,LOCK_EX|LOCK_NB)) exit(0); } else { $guard=locked($dir.'/supervisor.lock'); if ($guard===null) exit(0); }
        $budget=$dir.'/supervisor-retries.json'; $r=is_file($budget)||is_link($budget)?privateRecord($budget):['id'=>$id,'attempts'=>0,'next_at'=>0];
        if (($r['id']??null)!==$id||!is_int($r['attempts']??null)||$r['attempts']<0||!is_int($r['next_at']??null)) throw new RuntimeException('Invalid recovery budget');
        if ($r['attempts']>=3) throw new RuntimeException('Automatic recovery exhausted; inspect transaction and recover manually');
        if ($r['next_at']>time()) exit(0);
        $terminal=in_array($j['state'],['committed','rolled_back'],true);
        if (!$terminal && $j['mutation_started']) {
            // The launcher is copied from the previous verified installation before mutation.
            $launcher=$dir.'/recover.php'; $s=@lstat($launcher);
            if (!$s||realpath($launcher)!==$launcher||($s['mode']&0170000)!==0100000||($s['mode']&0077)!==0||$s['uid']!==posix_geteuid()||$s['nlink']!==1) throw new RuntimeException('Missing protected recovery launcher');
            $meta=privateRecord($dir.'/recovery.json');
            if (($meta['root']??null)!==$root||($meta['transaction']??null)!==$id||($meta['backup']??null)!==$root.'/storage/app/backups/production/'.($j['backup']??'')) throw new RuntimeException('Recovery backup identity mismatch');
            if (!getenv('BACKUP_KEY_FILE')) throw new RuntimeException('Provision BACKUP_KEY_FILE through the host service environment');
            $command=[PHP_BINARY,$launcher];
        } else $command=[PHP_BINARY,$root.'/bin/cajeer','update:rollback','--transaction='.$id];
        $r['attempts']++; $r['next_at']=time()+60*$r['attempts']; persist($budget,$r);
    } finally { if (is_resource($child)) fclose($child); fclose($lease); }
    // The child acquires the exclusive application lease again and checks the exact transaction.
    // Never execute another update in this process after recovery.
    try { exit(launch($command)); } finally { if (is_resource($guard)) fclose($guard); }
} catch (Throwable $e) { fwrite(STDERR,'Recovery supervisor: '.$e->getMessage()."\n"); exit(1); }
