<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Content;

use CajeerEngine\Media\ImageVariants;
use CajeerEngine\Update\OperationFiles as F;

/** Generate only decoded raster derivatives in a private, disposable directory. */
final class ForumImageProcessor
{
    public const NAMES=['thumbnail','medium','large'];
    public static function available(): bool {return (new ImageVariants(sys_get_temp_dir()))->available();}
    public static function generate(array $attachment,string $data): array
    {
        $dim=@getimagesizefromstring($data);
        if(!$dim||!in_array($dim['mime'],['image/jpeg','image/png','image/webp'],true)||$dim['mime']!==$attachment['mime']||$dim[0]<1||$dim[1]<1||$dim[0]*$dim[1]>16000000)throw new \InvalidArgumentException('Unsupported raster or pixel budget');
        $root=sys_get_temp_dir().'/cajeer-forum-media-'.bin2hex(random_bytes(16));
        if(!mkdir($root,0700))throw new \RuntimeException('Cannot allocate private media workspace');
        try {
            F::dir($root.'/storage/app/media-work');$source=$root.'/source';F::write($source,$data);
            $item=['id'=>$attachment['id'],'checksum'=>$attachment['sha256'],'mime_type'=>$dim['mime'],'width'=>$dim[0],'height'=>$dim[1],'visibility'=>'private'];
            $created=(new ImageVariants($root))->create($source,$item,self::NAMES);$result=[];
            foreach($created as $name=>$v){$bytes=file_get_contents(F::path($root,'storage/app/media-private/'.$v['path']));if($bytes===false||strlen($bytes)>5242880||strlen($bytes)<1)throw new \RuntimeException('Derivative exceeds byte budget');
                $meta=['mime'=>$v['mime_type'],'width'=>$v['width'],'height'=>$v['height'],'size_bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'data'=>$bytes];
                if(!self::valid($meta,$bytes))throw new \RuntimeException('Invalid derivative');$result[$name]=$meta;
            }
            if(count($result)!==count(self::NAMES))throw new \RuntimeException('Incomplete derivative set');return $result;
        } finally {
            $walk=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
            foreach($walk as $entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($root);
        }
    }
    public static function valid(array $v,string $data): bool
    {
        if(strlen($data)!==(int)$v['size_bytes']||strlen($data)<1||strlen($data)>5242880||!hash_equals((string)$v['sha256'],hash('sha256',$data)))return false;
        $dim=@getimagesizefromstring($data);
        return $dim!==false&&in_array($v['mime'],['image/jpeg','image/png'],true)&&$dim['mime']===$v['mime']&&$dim[0]===(int)$v['width']&&$dim[1]===(int)$v['height']&&max($dim[0],$dim[1])<=1600;
    }
}
