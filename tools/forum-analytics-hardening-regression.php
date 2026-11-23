<?php
/** Request-origin security regression for the production native analytics collector. */
declare(strict_types=1);
namespace Symfony\Component\HttpFoundation {
    if (!class_exists(Request::class)) {
        final class TestHeaders {
            public function __construct(private array $values) {}
            public function get(string $key,mixed $default=''):mixed{return $this->values[$key]??$default;}
        }
        class Request {
            public TestHeaders $headers;
            public function __construct(array $h,private bool $secure=true,private string $method='POST'){$this->headers=new TestHeaders($h);}
            public function isSecure():bool{return $this->secure;}
            public function getMethod():string{return $this->method;}
            public function getHttpHost():string{return 'forum.test';}
        }
    }
}
namespace {
    $root=dirname(__DIR__);
    spl_autoload_register(static function(string $class)use($root):void{
        if (str_starts_with($class,'CajeerEngine\\')) {
            $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
            if(is_file($file))require_once $file;
        }
    });
    use CajeerEngine\Forum\Service\ForumNativeAnalytics;
    use CajeerEngine\Forum\ForumException;
    use Symfony\Component\HttpFoundation\Request;
    use CajeerEngine\Database\DatabaseManager;
    putenv('APP_KEY=1234567890abcdef1234567890abcdef1234567890abcdef');
    $collector=(new ReflectionClass(ForumNativeAnalytics::class))->newInstanceWithoutConstructor();
    $headers=['Origin'=>'https://forum.test','Sec-Fetch-Site'=>'same-origin','Sec-Fetch-Mode'=>'cors','Content-Type'=>'application/json'];
    $count=0;
    foreach ([
        ['Origin'=>'https://evil.test'],['Origin'=>'null'],['Sec-Fetch-Site'=>'cross-site'],
        ['Sec-Fetch-Site'=>'none'],['Sec-Fetch-Site'=>''],['Sec-Fetch-Mode'=>'navigate'],
        ['Sec-Fetch-Mode'=>''],['Content-Type'=>'text/plain'],['Content-Type'=>'application/x-www-form-urlencoded']
    ] as $replaced) {
        try {$collector->collect(new Request(array_replace($headers,$replaced)),'not-a-receipt');}
        catch(ForumException $e){if($e->status===403){$count++;continue;}}
        throw new RuntimeException('Collector accepted invalid origin or Fetch Metadata');
    }
    foreach ([false,'GET'] as $variant) {
        try {$collector->collect(new Request($headers,$variant!==false,$variant==='GET'?'GET':'POST'),'not-a-receipt');}
        catch(ForumException $e){if($e->status===403){$count++;continue;}}
        throw new RuntimeException('Collector accepted HTTP or GET');
    }
    try {$collector->collect(new Request($headers),'not-a-receipt');}
    catch(ForumException $e){if($e->status===403)$count++;else throw $e;}
    echo "Forum analytics 1.2.11 request hardening: {$count} PASS\n";
}
