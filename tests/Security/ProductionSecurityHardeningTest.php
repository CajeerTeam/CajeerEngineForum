<?php

declare(strict_types=1);
namespace CajeerEngine\Tests\Security;

use CajeerEngine\Http\Middleware\ErrorHandlerMiddleware;
use CajeerEngine\Security\PublicHttpClient;
use CajeerEngine\Integration\WebhookRepository;
use PHPUnit\Framework\TestCase;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Runtime\RuntimeLogger;
use Symfony\Component\HttpFoundation\Request;

final class ProductionSecurityHardeningTest extends TestCase
{
    public function testStoredWebhookRejectsNonPublicDestinationsBeforeWriting(): void
    {
        $root=sys_get_temp_dir().'/ce-092-'.bin2hex(random_bytes(8));mkdir($root,0700);
        try {
            $repo=new WebhookRepository($root);
            foreach(['http://8.8.8.8','https://127.0.0.1','https://169.254.169.254/','https://[::1]/'] as $url) {
                try {$repo->create(['url'=>$url]);self::fail('Unsafe endpoint stored: '.$url);}catch(\InvalidArgumentException){}
                self::assertSame([], $repo->all());
            }
            $item=$repo->create(['url'=>'https://8.8.8.8/hook']);
            try {$repo->update($item['id'],['url'=>'https://127.0.0.1']);self::fail('Unsafe update accepted');}catch(\InvalidArgumentException){}
            self::assertSame($item['url'],$repo->find($item['id'])['url']);
        } finally {
            if(is_dir($root.'/storage/app/webhook-locks'))foreach(glob($root.'/storage/app/webhook-locks/*')?:[] as $file)unlink($file);
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($root);
        }
    }

    public function testUnexpectedRuntimeExceptionDoesNotExposeOperationalSecrets(): void
    {
        $root=sys_get_temp_dir().'/ce-092-logger-'.bin2hex(random_bytes(8));mkdir($root,0700);
        try {
            $request=Request::create('/api/v1/secure','GET');
            $request->attributes->set('request_id','security-regression');
            $handler=new ErrorHandlerMiddleware(new ConfigRepository(dirname(__DIR__,2)),new RuntimeLogger($root));
            $response=$handler->process($request,static function(): never {
                throw new \RuntimeException('leak=DATABASE_PASSWORD_SUPER_SECRET /srv/production/secrets');
            });
            self::assertSame(400,$response->getStatusCode());
            $body=$response->getContent();
            self::assertIsString($body);
            self::assertStringNotContainsString('DATABASE_PASSWORD',$body);
            self::assertStringNotContainsString('/srv/production/secrets',$body);
            $json=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
            self::assertSame('security-regression',$json['error']['request_id']);
            self::assertSame('runtime_error',$json['error']['code']);
        } finally {
            foreach(glob($root.'/storage/logs/*')?:[] as $file)unlink($file);
            foreach([$root.'/storage/logs',$root.'/storage',$root] as $dir)if(is_dir($dir))rmdir($dir);
        }
    }

    public function testRedirectAndMetadataTargetsAreDenied(): void
    {
        foreach(['http://127.0.0.1','http://169.254.169.254/latest/meta-data','file:///etc/passwd','http://8.8.8.8:9000/'] as $url){
            $rejected=false;
            try { PublicHttpClient::target($url); } catch (\InvalidArgumentException) { $rejected=true; }
            self::assertTrue($rejected, 'SSRF target permitted: '.$url);
        }
    }
}
