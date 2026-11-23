<?php

declare(strict_types=1);

namespace Symfony\Component\HttpFoundation {
    if (!class_exists(Request::class)) {
        class TestForumBag {
            public function __construct(private array $data=[]) {}
            public function get(string $key,mixed $default=null): mixed {return $this->data[$key]??$default;}
            public function has(string $key): bool {return array_key_exists($key,$this->data);}
            public function set(string $key,mixed $value): void {$this->data[$key]=$value;}
            public function all(): array {return $this->data;}
        }
        class Request {
            public TestForumBag $headers;public TestForumBag $query;
            public function __construct(private string $url,private string $method='GET',array $headers=[]) {
                parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
                $this->query=new TestForumBag($query);$this->headers=new TestForumBag($headers);
            }
            public function getPathInfo(): string {return (string)parse_url($this->url,PHP_URL_PATH);}
            public function getHost(): string {return (string)parse_url($this->url,PHP_URL_HOST);}
            public function getHttpHost(): string {return $this->getHost().(parse_url($this->url,PHP_URL_PORT)?':'.parse_url($this->url,PHP_URL_PORT):'');}
            public function getMethod(): string {return $this->method;}
            public function isSecure(): bool {return parse_url($this->url,PHP_URL_SCHEME)==='https';}
        }
        class Response {
            public TestForumBag $headers;
            public function __construct(private string $html,private int $status=200,array $headers=[]) {$this->headers=new TestForumBag($headers);}
            public function getContent(): string {return $this->html;}
            public function setContent(string $html): void {$this->html=$html;}
            public function getStatusCode(): int {return $this->status;}
        }
    }
}

namespace {
    use CajeerEngine\Forum\Service\ForumNativeAnalytics as N;
    use CajeerEngine\Forum\ForumException;
    use Symfony\Component\HttpFoundation\{Request,Response};
    $root=dirname(__DIR__);
    spl_autoload_register(static function(string $class) use($root):void {
        if(str_starts_with($class,'CajeerEngine\\')){
            $path=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
            if(is_file($path))require_once $path;
        }
    });
    $checks=0;
    $check=static function(bool $yes,string $why)use(&$checks):void{
        if(!$yes)throw new \RuntimeException('FAIL '.$why);
        $checks++;echo 'PASS '.$why."\n";
    };
    $uuid='01234567-89ab-4cde-8fab-0123456789ab';
    $check(N::pageKind('/forum',[])==='index','index is a supported native event');
    $check(N::pageKind('/forum/boards/'.$uuid,[])==='board','board page counted');
    $check(N::pageKind('/forum/threads/'.$uuid,['page'=>'1'])==='thread','first page counted as thread');
    $check(N::pageKind('/forum/threads/'.$uuid,['page'=>'2'])==='page','pagination counted independently');
    foreach(['/forum/search','/forum/members/'.$uuid,'/forum/account','/admin','/forum/threads/INVALID'] as $path)
        $check(N::pageKind($path,[])===null,'private or unrecognized route rejected');
    foreach([['secret'=>'p'],['page'=>'01'],['page'=>'-1'],['page'=>[]],['unread'=>'1'],['page'=>'10000']] as $query)
        $check(N::pageKind('/forum/threads/'.$uuid,$query)===null,'unexpected query rejected');
    putenv('APP_KEY');
    $check(N::issue('index')===null,'cannot issue receipt without APP_KEY');
    putenv('APP_KEY=1234567890abcdef1234567890abcdef1234567890abcdef');
    $now=time();$receipt=N::issue('thread',$now);
    $check(is_string($receipt) && N::verify($receipt,$now)===['forum_thread_view',explode('.',$receipt)[3]],'signed event has no actor or URL');
    $check(N::issue('bad')===null,'unknown event cannot be issued');
    foreach([$receipt.'bad',str_replace('thread','index',$receipt),str_replace('v1','v2',$receipt)] as $forged){
        try{N::verify($forged,$now);throw new \RuntimeException('forgery accepted');}
        catch(ForumException $e){$check($e->status===403,'tampered receipt rejected');}
    }
    try{N::verify($receipt,$now+1801);throw new \RuntimeException('expired receipt accepted');}
    catch(ForumException $e){$check($e->status===403,'expired receipt rejected');}
    try{N::verify($receipt,$now-61);throw new \RuntimeException('future receipt accepted');}
    catch(ForumException $e){$check($e->status===403,'future receipt rejected');}
    $html='<!doctype html><html><head><title>forum</title></head><body>Posts</body></html>';
    $headers=['X-Robots-Tag'=>'index, follow'];
    foreach([
        ['http://forum.test/forum/threads/'.$uuid,true,true],
        ['https://forum.test/forum/threads/'.$uuid.'?unread=1',true,true],
        ['https://forum.test/forum/search',true,true],
        ['https://forum.test/forum/threads/'.$uuid,true,true,'POST'],
        ['https://forum.test/forum/threads/'.$uuid,false,true],
        ['https://forum.test/forum/threads/'.$uuid,true,false],
    ] as $case){
        [$url,$enabled,$guest]=$case;
        $response=N::enhance(new Response($html,200,$headers),new Request($url,$case[3]??'GET'),$root,$enabled,$guest);
        $check(!str_contains($response->getContent(),'data-ce-native-receipt='),'unapproved/disabled/non-guest render has no tracker');
    }
    $response=N::enhance(new Response($html,200,$headers),new Request('https://forum.test/forum/threads/'.$uuid.'?page=2'),$root,true,true);
    $check(str_contains($response->getContent(),'data-ce-native-receipt='),'approved public page includes real first-party collector');
    $check(substr_count($response->getContent(),'data-ce-consent-config=')===1,'native collection uses one consent runtime');
    preg_match('/data-ce-native-receipt="([^"]+)"/',$response->getContent(),$m);
    $check(N::verify($m[1]) [0]==='forum_paginated_view','rendered pagination receipt is signed');
    $again=N::enhance(new Response($html,200,$headers),new Request('https://forum.test/forum/threads/'.$uuid.'?page=2'),$root,true,true);
    preg_match('/data-ce-native-receipt="([^"]+)"/',$again->getContent(),$n);
    $check($m[1]!==$n[1],'each HTML response gets a separate one-use receipt');
    echo "Forum native analytics: {$checks} PASS\n";
}
