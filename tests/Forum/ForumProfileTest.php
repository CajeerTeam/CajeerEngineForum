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
    public function testImageReencoding(): void
    {
        if(!function_exists('imagecreatetruecolor')) self::markTestSkipped('PHP GD required for raster encoding');
        $source=imagecreatetruecolor(600,300);
        ob_start();imagepng($source);$raw=(string)ob_get_clean();
        $data=base64_decode(ProfileImage::encode('avatar',base64_encode($raw)),true);
        self::assertIsString($data);$size=getimagesizefromstring($data);
        self::assertSame(256,$size[0]);self::assertSame(128,$size[1]);self::assertSame(IMAGETYPE_JPEG,$size[2]);
    }
}
