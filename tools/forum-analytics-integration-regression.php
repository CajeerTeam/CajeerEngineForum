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
        $check(count($defaults['metrika_goals'])===6&&count(array_filter($defaults['metrika_goals']))===0,'Metrica goals disabled until operator configures them');
        $goals=$defaults['metrika_goals'];$goals['forum_thread_view']='thread_goal';$goals['forum_outbound_click']='outbound_goal';
        $check(ForumCommunitySettings::validateMetrikaGoals($goals)===$goals,'production goal identifiers accepted');
        foreach (['bad goal','<script>','',str_repeat('z',65)] as $bad) {
            if($bad==='')continue;
            $invalid=$goals;$invalid['forum_thread_view']=$bad;
            try {ForumCommunitySettings::validateMetrikaGoals($invalid);throw new \RuntimeException('Invalid goal accepted');}
            catch(ForumException $e){$check($e->status===422,'invalid goal rejected');}
        }
        $duplicate=$goals;$duplicate['forum_board_view']='thread_goal';
        try {ForumCommunitySettings::validateMetrikaGoals($duplicate);throw new \RuntimeException('Duplicate goal accepted');}
        catch(ForumException $e){$check($e->status===422,'duplicate target rejected');}
        $ga4=$defaults['ga4_events'];
        $check(count($ga4)===6&&$ga4['forum_thread_view']==='forum_thread_view','GA4 named custom events ready for an opted-in public forum');
        $check(ForumCommunitySettings::validateGa4Events($ga4)===$ga4,'GA4 production-safe event names accepted');
        $ga4['forum_thread_view']='forum_discussion_read';
        $ga4['forum_outbound_click']='';
        $check(ForumCommunitySettings::validateGa4Events($ga4)===$ga4,'GA4 custom mapping and per-event opt-out accepted');
        foreach(['invalid event','G-AAAAAAA','ga_reserved','page_view','session_start','purchase','login',str_repeat('x',41),'<script>',42] as $bad) {
            $invalid=$ga4;$invalid['forum_board_view']=$bad;
            try {ForumCommunitySettings::validateGa4Events($invalid);throw new \RuntimeException('Invalid GA4 event accepted');}
            catch(ForumException $e){$check($e->status===422,'invalid or reserved GA4 event rejected');}
        }
        $duplicate=$ga4;$duplicate['forum_index_view']='forum_discussion_read';
        try {ForumCommunitySettings::validateGa4Events($duplicate);throw new \RuntimeException('Duplicate GA4 event accepted');}
        catch(ForumException $e){$check($e->status===422,'duplicate GA4 names rejected');}
        $alternatives=ForumCommunitySettings::alternativeEvents();
        $check(count($alternatives)===6&&$alternatives['forum_thread_view']==='forum_thread_view','alternative event destinations default to six public Forum activities');
        foreach(['matomo','plausible','gtm'] as $provider) {
            $check($defaults[$provider.'_events']===$alternatives,'default '.$provider.' destinations persisted in Forum policy');
            $custom=$alternatives;$custom['forum_board_view']='forum_board_opened';$custom['forum_attachment_click']='';
            $check(ForumCommunitySettings::validateAlternativeEvents($custom,$provider)===$custom,'custom '.$provider.' destination and per-event opt-out accepted');
            foreach(['bad event','page_view','pageview','ce_forbidden','google_event','purchase','G-INVALID',str_repeat('x',41),22] as $invalid){
                $broken=$custom;$broken['forum_thread_view']=$invalid;
                try{ForumCommunitySettings::validateAlternativeEvents($broken,$provider);throw new \RuntimeException('Invalid destination accepted');}
                catch(ForumException $e){$check($e->status===422,'invalid '.$provider.' destination rejected');}
            }
            $duplicate=$custom;$duplicate['forum_thread_view']='forum_board_opened';
            try{ForumCommunitySettings::validateAlternativeEvents($duplicate,$provider);throw new \RuntimeException('Duplicate destination accepted');}
            catch(ForumException $e){$check($e->status===422,'duplicate '.$provider.' destination rejected');}
            try{ForumCommunitySettings::validateAlternativeEvents(['bad'=>'extra'],$provider);throw new \RuntimeException('Unexpected provider key accepted');}
            catch(ForumException $e){$check($e->status===422,'unexpected '.$provider.' key rejected');}
        }
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
        $id='01234567-89ab-4cde-8fab-0123456789ab';
        $check(ForumAnalyticsIntegration::publicPath('/forum'),'public forum root trackable');
        $check(ForumAnalyticsIntegration::publicPath('/forum/threads/'.$id),'public thread eligible after ACL check');
        $check(ForumAnalyticsIntegration::publicPath('/forum/boards/'.$id),'public board eligible after ACL check');
        foreach(['/forum/members/'.$id,'/forum/conversations','/forum/account/security','/forum/acp','/forum/threads/bad'] as $private){
            $check(!ForumAnalyticsIntegration::publicPath($private),'non-public path cannot be marked: '.$private);
        }
        $adapter=new ForumAnalyticsIntegration($work);
        $req=Request::create('https://forum.test/forum','GET');
        $html='<!doctype html><html><head></head><body><h1>Public forum</h1></body></html>';
        $result=$adapter->decorate($html,$req);
        $check(!$result['active']&&$result['html']===$html,'no tracking when core counters disabled');
        $tracking=new TrackingConfig($work);
        $cfg=TrackingConfig::defaults();$cfg['enabled']=true;$cfg['hosts']=['forum.test'];$cfg['metrika_id']='12345678';$cfg['ga4_id']='G-ABCDEFGH';
        $tracking->update($cfg);
        $result=$adapter->decorate($html,$req,$goals);
        $check(str_contains($result['html'],'data-ce-forum-metrika-goals=')&&str_contains($result['html'],'thread_goal'),'operator goal configuration is served only on approved SSR page');
        $check(str_contains($result['html'],'data-ce-forum-ga4-events=')&&str_contains($result['html'],'forum_thread_view'),'GA4 custom events bound to same single approved consent script');
        $check(str_contains($result['html'],'data-ce-forum-alternative-events=')&&str_contains($result['html'],'matomo')&&str_contains($result['html'],'plausible')&&str_contains($result['html'],'gtm'),'three alternative provider event maps attached only to server-approved consent script');
        $customAlternative=['matomo'=>$alternatives,'plausible'=>$alternatives,'gtm'=>$alternatives];
        $customAlternative['matomo']['forum_thread_view']='topic_viewed';
        $check(str_contains($adapter->decorate($html,$req,$goals,$defaults['ga4_events'],$customAlternative)['html'],'topic_viewed'),'operator Matomo customization included in live public render');
        $customAlternative['plausible']['forum_thread_view']='google_reserved';
        $check(!$adapter->decorate($html,$req,$goals,$defaults['ga4_events'],$customAlternative)['active'],'invalid alternative destination fails closed before inserting any counter');

        $ga4['forum_thread_view']='bad event';
        $check(!$adapter->decorate($html,$req,$goals,$ga4)['active'],'corrupt GA4 event mapping fails closed');
        $ga4=$defaults['ga4_events'];
        $ga4['forum_thread_view']='forum_discussion_read';
        $check(str_contains($adapter->decorate($html,$req,$goals,$ga4)['html'],'forum_discussion_read'),'custom GA4 event name included only on valid public response');
        $check($result['active'] && substr_count($result['html'],'data-ce-consent-config=')===1,'live consent and counter engine inserted once');
        $check(str_contains($result['html'],'data-ce-forum-approved-path="/forum"'),'server-authorized forum path included in consent runtime');
        $check(!$adapter->decorate($html,Request::create('https://forum.test/forum/members/'.$id))['active'],'member profile never tracked');
        $check(!$adapter->decorate($html,Request::create('https://forum.test/forum/conversations'),$goals)['active'],'private messages never tracked');
        $invalid=$goals;$invalid['forum_thread_view']='invalid goal';
        $check(!$adapter->decorate($html,$req,$invalid)['active'],'corrupt goal configuration fails closed');
        $check(!$adapter->decorate($html,Request::create('https://forum.test/forum','HEAD'))['active'],'HEAD does not initialize browser tracking');
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
        $check(str_contains($csp,'https://www.googletagmanager.com'),'approved public response CSP permits GA4 Google tag');
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
