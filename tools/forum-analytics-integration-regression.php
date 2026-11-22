<?php

declare(strict_types=1);

namespace {
    $root=dirname(__DIR__);
    if(is_file($root.'/vendor/autoload.php'))require_once $root.'/vendor/autoload.php';
}

namespace Symfony\Component\HttpFoundation {
    if (!class_exists(Request::class)) {
        class TestBag {
            public function __construct(private array $values=[]) {}
            public function set(string $name,mixed $value):void {$this->values[$name]=$value;}
            public function get(string $name,mixed $default=null):mixed {return $this->values[$name]??$default;}
            public function remove(string $name):void {unset($this->values[$name]);}
        }
        class Request {
            public TestBag $headers;public TestBag $attributes;
            public function __construct(private string $url,private string $method='GET') {
                $this->headers=new TestBag();$this->attributes=new TestBag();
            }
            public static function create(string $url,string $method='GET'):self{return new self($url,$method);}
            public function isSecure():bool{return parse_url($this->url,PHP_URL_SCHEME)==='https';}
            public function getHost():string{return (string)parse_url($this->url,PHP_URL_HOST);}
            public function getMethod():string{return $this->method;}
            public function getPathInfo():string{return (string)parse_url($this->url,PHP_URL_PATH);}
        }
        class Response {
            public TestBag $headers;
            public function __construct(private string $content='',private int $status=200,array $headers=[]) {
                $this->headers=new TestBag($headers);
            }
        }
    }
}

namespace {
    use CajeerEngine\Forum\Service\{ForumAnalyticsIntegration,ForumCommunitySettings};
    use CajeerEngine\Http\Middleware\SecurityHeadersMiddleware;
    use CajeerEngine\Privacy\{TrackingConfig,ConsentPolicy};
    use Symfony\Component\HttpFoundation\{Request,Response};
    use CajeerEngine\Forum\ForumException;

    spl_autoload_register(static function(string $class)use($root):void {
        if(str_starts_with($class,'CajeerEngine\\')){
            $path=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
            if(is_file($path))require $path;
        }
    });
    $count=0;
    $check=static function(bool $condition,string $message)use(&$count):void {
        if(!$condition)throw new \RuntimeException('FAIL '.$message);
        $count++;echo 'PASS '.$message."\n";
    };
    $work=sys_get_temp_dir().'/forum-analytics-'.bin2hex(random_bytes(8));
    mkdir($work,0700,true);
    try {
        foreach(['consent.js','consent.css'] as $name)\CajeerEngine\Update\OperationFiles::copy($root.'/resources/consent/'.$name,$work.'/resources/consent/'.$name,0644);
        $defaults=ForumCommunitySettings::defaults();
        $check($defaults['analytics_enabled']===false,'forum analytics opt-in defaults off');
        if(extension_loaded('mbstring')) {
            $check(ForumCommunitySettings::validate($defaults)['analytics_enabled']===false,'policy accepts disabled analytics');
            $check(ForumCommunitySettings::validate(array_replace($defaults,['analytics_enabled'=>true]))['analytics_enabled']===true,'policy permits explicit activation');
            try {ForumCommunitySettings::validate(array_replace($defaults,['analytics_enabled'=>'true']));throw new \RuntimeException('String accepted');}
            catch(ForumException $e){$check($e->status===422,'non-boolean analytics policy rejected');}
        } else echo "SKIP policy validation (mbstring extension unavailable)\n";
        $check(!ForumAnalyticsIntegration::eligible(false,true,true,false),'forum switch required');
        $check(!ForumAnalyticsIntegration::eligible(true,false,true,false),'unindexed content excluded');
        $check(!ForumAnalyticsIntegration::eligible(true,true,false,false),'authenticated potentially private content excluded');
        $check(!ForumAnalyticsIntegration::eligible(true,true,true,true),'theme preview excluded');
        $check(ForumAnalyticsIntegration::eligible(true,true,true,false),'public guest content eligible');
        $adapter=new ForumAnalyticsIntegration($work);
        $req=Request::create('https://forum.test/forum','GET');
        $html='<!doctype html><html><head></head><body><h1>Public forum</h1></body></html>';
        $result=$adapter->decorate($html,$req);
        $check(!$result['active']&&$result['html']===$html,'no tracking when core counters disabled');
        $tracking=new TrackingConfig($work);
        $cfg=TrackingConfig::defaults();$cfg['enabled']=true;$cfg['hosts']=['forum.test'];$cfg['metrika_id']='12345678';
        $tracking->update($cfg);
        $result=$adapter->decorate($html,$req);
        $check($result['active'] && substr_count($result['html'],'data-ce-consent-config=')===1,'live consent and counter engine inserted once');
        $check(str_contains($result['html'],'/assets/ce-consent/consent.js')&&!str_contains($result['html'],'src="https://mc.yandex.ru'),'optional provider remains inert until consent');
        $other=$adapter->decorate($html,Request::create('https://other.test/forum','GET'));
        $check(!$other['active'],'unapproved host excluded');
        $http=$adapter->decorate($html,Request::create('http://forum.test/forum','GET'));
        $check(!$http['active'],'non-TLS forum excluded');
        $post=$adapter->decorate($html,Request::create('https://forum.test/forum','POST'));
        $check(!$post['active'],'mutation responses excluded');
        $middleware=new SecurityHeadersMiddleware($work);
        $req->attributes->set('matched_route','forum.page');
        $response=$middleware->process($req,static fn(Request $r)=>new Response($result['html'],200,['Content-Type'=>'text/html; charset=UTF-8','X-Forum-Analytics-Public'=>'1']));
        $csp=(string)$response->headers->get('Content-Security-Policy');
        $check(str_contains($csp,'https://mc.yandex.ru'),'marked public forum response receives provider CSP');
        $check($response->headers->get('X-Forum-Analytics-Public')===null,'internal CSP signal removed from client response');
        $plain=$middleware->process($req,static fn(Request $r)=>new Response('<html/>',200,['Content-Type'=>'text/html']));
        $check(!str_contains((string)$plain->headers->get('Content-Security-Policy'),'mc.yandex.ru'),'unmarked forum response cannot load analytics origin');
        $version=(new ConsentPolicy($work))->publicConfig()['version'];
        $cfg=$tracking->get();$cfg['enabled']=false;$tracking->update($cfg);
        $check((new ConsentPolicy($work))->publicConfig()['version']!==$version,'counter toggle invalidates cached consent version');
        $check(!$adapter->decorate($html,$req)['active'],'turning off core tracking disables forum injection');
        echo "Forum Analytics Integration: {$count} PASS\n";
    } finally {
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($work,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());
        }
        rmdir($work);
    }
}
