<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

/** Fixed XenForo 2.2/2.3 avatar paths relative to a frozen data/avatars root. */
final class XenForoAvatar
{
    public static function paths(string $root,int $sourceUserId):array
    {
        if($sourceUserId<=0)throw new \InvalidArgumentException('Invalid XenForo avatar user ID');
        $root=rtrim($root,DIRECTORY_SEPARATOR);
        $shard=(string)intdiv($sourceUserId,1000);
        $name=$sourceUserId.'.jpg';
        // Prefer original, fall back to large published rendition when absent.
        return [$root.'/o/'.$shard.'/'.$name,$root.'/l/'.$shard.'/'.$name];
    }
}
