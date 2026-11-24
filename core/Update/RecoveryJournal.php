<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

/** The per-transaction fsynced journal precedes the public active pointer.
 * Never infer a rollback from a stale pointer when a terminal decision exists.
 * Read only under the exclusive operation lease. */
final class RecoveryJournal
{
    public static function active(string $root): array
    {
        $a=self::privateRecord(OperationFiles::path($root,'storage/app/updates/active.json'));
        $id=$a['id']??null;
        if (!is_string($id)||!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$id)) throw new \RuntimeException('Invalid recovery transaction');
        $dir=OperationFiles::path($root,'storage/app/updates/transactions/'.$id);
        if (!is_dir($dir)||fileowner($dir)!==posix_geteuid()||(fileperms($dir)&0077)!==0) throw new \RuntimeException('Unsafe transaction directory');
        $j=self::privateRecord($dir.'/journal.json');
        foreach (['id','previous_version','target_version','sha256'] as $key) if (!isset($a[$key],$j[$key]) || $a[$key]!==$j[$key]) throw new \RuntimeException('Recovery journal identity mismatch');
        if (!in_array($j['state']??null,['prepared','backup','verifying_backup','backed_up','applying','migrating','recovering','recovery_required','committed','rolled_back'],true)) throw new \RuntimeException('Invalid recovery phase');
        if (($j['recovery_protocol']??null)===2 && ($a['recovery_protocol']??null)!==2) throw new \RuntimeException('Recovery protocol mismatch');
        return $j;
    }
    private static function privateRecord(string $path): array
    {
        clearstatcache(true,$path); $st=@lstat($path);
        if (!$st||($st['mode']&0170000)!==0100000||($st['mode']&0077)!==0||$st['uid']!==posix_geteuid()||$st['nlink']!==1) throw new \RuntimeException('Unsafe recovery record');
        return OperationFiles::read($path);
    }
}
