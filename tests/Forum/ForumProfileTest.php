<?php

declare(strict_types=1);
namespace CajeerEngine\Tests\Forum;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Service\MemberProfileService;
use CajeerEngine\Forum\Security\ProfileImage;
use PHPUnit\Framework\TestCase;

final class ForumProfileTest extends TestCase
{
    public function testVisibilityMatrix(): void
    {
        foreach (['public'=>[true,true,true],'members'=>[false,true,true],'private'=>[false,false,true]] as $visibility=>$expected) {
            foreach(['','other','owner'] as $i=>$viewer) self::assertSame($expected[$i],MemberProfileService::visible($visibility,$viewer,'owner'));
        }
    }
    public function testRasterValidationRejectsActiveContent(): void
    {
        $this->expectException(ForumException::class);
        ProfileImage::encode('avatar',base64_encode('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'));
    }
    public function testStoredImageRejectsCorruptBytes(): void
    {
        self::assertNull(ProfileImage::decodeStored('avatar',base64_encode('<svg/>')));
        self::assertNull(ProfileImage::decodeStored('avatar','not base64!'));
        self::assertNull(ProfileImage::decodeStored('cover',base64_encode("\xff\xd8\xff\xd9")));
        self::assertNull(ProfileImage::decodeStored('unknown',''));
    }
    public function testImageReencoding(): void
    {
        if(!function_exists('imagecreatetruecolor')) self::markTestSkipped('PHP GD required for raster encoding');
        $source=imagecreatetruecolor(600,300);
        ob_start();imagepng($source);$raw=(string)ob_get_clean();
        $encoded=ProfileImage::encode('avatar',base64_encode($raw));
        $data=ProfileImage::decodeStored('avatar',$encoded);
        self::assertNull(ProfileImage::decodeStored('avatar',base64_encode(substr((string)$data,0,-2))));
        self::assertIsString($data);$size=getimagesizefromstring($data);
        self::assertSame(256,$size[0]);self::assertSame(128,$size[1]);self::assertSame(IMAGETYPE_JPEG,$size[2]);
    }
}
