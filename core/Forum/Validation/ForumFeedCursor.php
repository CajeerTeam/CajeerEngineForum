<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Validation;

use CajeerEngine\Forum\ForumException;

final class ForumFeedCursor
{
    public static function decode(mixed $value): ?array
    {
        if ($value==='') return null;
        if (!is_string($value)||strlen($value)>256||!preg_match('/\A[A-Za-z0-9_-]+\z/D',$value)) throw new ForumException('Некорректный курсор.',400);
        $raw=base64_decode(strtr($value,'-_','+/'),true);
        $parts=$raw===false?[]:explode('|',$raw);
        if(count($parts)!==2||!preg_match('/\A(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4])(?::?[0-5]\d)?)?\z/D',$parts[0],$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1])||(int)$m[4]>23||(int)$m[5]>59||(int)$m[6]>59) throw new ForumException('Некорректный курсор.',400);
        try { $id=ForumValidator::id($parts[1]); } catch(ForumException) { throw new ForumException('Некорректный курсор.',400); }
        return ['at'=>$parts[0],'id'=>$id];
    }
    public static function encode(array $row): string
    {
        return rtrim(strtr(base64_encode($row['updated_at'].'|'.$row['id']),'+/','-_'),'=');
    }
}
