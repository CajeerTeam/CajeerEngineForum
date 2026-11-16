<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

use CajeerEngine\Forum\ForumVersion;

/** Pure release policy plus bounded disk/inventory checks before a deployment mutates live files. */
final class UpdatePreflight
{
    public static function identity(array $manifest, string $current, string $channel, ?string $expected): void
    {
        $version=ForumVersion::validate($manifest['version']??null);
        if (($manifest['edition']??null)!=='forum'||($manifest['mode']??null)!=='dist') throw new \RuntimeException('Only Forum Edition dist can be applied');
        if ($expected!==null && ForumVersion::validate($expected)!==$version) throw new \RuntimeException('Approved target version differs from signed package');
        if (version_compare($version,$current,'<=')) throw new \RuntimeException('Target must be newer; use verified rollback for downgrade');
        $actual=self::channelOf($version);
        if (!in_array($channel,['stable','rc','beta','dev'],true)||($channel!=='dev'&&$actual!==$channel)) throw new \RuntimeException('Signed release does not match configured update channel');
    }

    public static function channelOf(string $version): string
    {
        ForumVersion::validate($version);
        return str_contains($version,'-')?(preg_match('/-rc(?:[.0-9-]|$)/i',$version)?'rc':(preg_match('/-beta(?:[.0-9-]|$)/i',$version)?'beta':'dev')):'stable';
    }

    /** Historical SQL must not disappear or change, even if the candidate is correctly signed. */
    public static function migrations(string $root,array $checksums): void
    {
        foreach (['pgsql','sqlite','mysql'] as $driver) {
            foreach (glob($root.'/migrations/'.$driver.'/*.sql')?:[] as $file) {
                $relative='migrations/'.$driver.'/'.basename($file);
                if (!isset($checksums[$relative])||!hash_equals(OperationFiles::hash($file),$checksums[$relative])) throw new \RuntimeException('Historical migration changed or missing: '.$relative);
            }
        }
    }

    /** Conservative estimate: compressed copy + extracted stage + backup + replacement headroom.
     * Free space is checked on both code and storage mounts; a remote database has its own capacity policy.
     */
    public static function space(string $root,string $package,array $manifest,int $backupBytes): array
    {
        $zip=new \PharData($package); $expanded=0;
        foreach ($manifest['files'] as $file) $expanded+=$zip['cajeerengine/'.$file]->getSize();
        $reserve=64*1024*1024;
        $storage=$root.'/storage/app';
        $storageNeed=filesize($package)+$expanded+$backupBytes+$reserve;
        $codeNeed=$expanded+$reserve;
        $storageFree=disk_free_space($storage); $codeFree=disk_free_space($root);
        $same=stat($storage)['dev']===stat($root)['dev'];
        if ($storageFree===false||$codeFree===false||$storageFree<($same?$storageNeed+$codeNeed:$storageNeed)||$codeFree<$codeNeed) throw new \RuntimeException('Insufficient disk space for staged release and recovery set');
        return ['expanded_bytes'=>$expanded,'backup_estimate_bytes'=>$backupBytes,'storage_required_bytes'=>$storageNeed,'code_required_bytes'=>$codeNeed,'storage_free_bytes'=>(int)$storageFree,'code_free_bytes'=>(int)$codeFree,'shared_filesystem'=>$same];
    }
}
