<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Security;

use CajeerEngine\Forum\ForumException;

/** Decode only bounded raster images, discard metadata and original bytes. */
final class ProfileImage
{
    /** Validate stored output independently of GD, including after restore. */
    public static function decodeStored(string $kind,string $encoded): ?string
    {
        if (!in_array($kind,['avatar','cover'],true) || strlen($encoded)>1398104) return null;
        $raw=base64_decode($encoded,true);
        if ($raw===false || strlen($raw)>1048576 || !str_starts_with($raw,"\xff\xd8") || !str_ends_with($raw,"\xff\xd9")) return null;
        $info=@getimagesizefromstring($raw);
        if (!$info || $info[2]!==IMAGETYPE_JPEG || $info[0]<1 || $info[1]<1 || $info[0]>($kind==='avatar'?256:1200) || $info[1]>($kind==='avatar'?256:480)) return null;
        return $raw;
    }

    public static function encode(string $kind,mixed $input): string
    {
        if(!in_array($kind,['avatar','cover'],true))throw new ForumException('Некорректный тип изображения.',422);
        if(!is_string($input)||strlen($input)>2796204)throw new ForumException('Максимальный размер изображения 2 МБ.',413);
        $raw=base64_decode($input,true);
        if($raw===false||$raw===''||strlen($raw)>2097152)throw new ForumException('Некорректное изображение.',422);
        $info=@getimagesizefromstring($raw);
        if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)||$info[0]<1||$info[1]<1||$info[0]>4096||$info[1]>4096||$info[0]*$info[1]>4000000)throw new ForumException('Разрешены JPEG, PNG, WebP до 4 мегапикселей.',422);
        if(!function_exists('imagecreatefromstring')||!function_exists('imagejpeg'))throw new ForumException('Для загрузки изображений установите PHP GD.',503);
        $source=@imagecreatefromstring($raw);
        if($source===false)throw new ForumException('Не удалось декодировать изображение.',422);
        $scale=min(1,($kind==='avatar'?256:1200)/$info[0],($kind==='avatar'?256:480)/$info[1]);
        $w=max(1,(int)floor($info[0]*$scale));$h=max(1,(int)floor($info[1]*$scale));
        $target=imagecreatetruecolor($w,$h);
        if($target===false)throw new ForumException('Не удалось обработать изображение.',503);
        imagefilledrectangle($target,0,0,$w,$h,imagecolorallocate($target,255,255,255));
        if(!imagecopyresampled($target,$source,0,0,0,0,$w,$h,$info[0],$info[1]))throw new ForumException('Не удалось изменить размер изображения.',422);
        ob_start();
        try { $ok=imagejpeg($target,null,85);$bytes=(string)ob_get_contents(); } finally { ob_end_clean(); }
        if(!$ok||$bytes===''||strlen($bytes)>1048576)throw new ForumException('Не удалось сохранить изображение.',422);
        return base64_encode($bytes);
    }
}
