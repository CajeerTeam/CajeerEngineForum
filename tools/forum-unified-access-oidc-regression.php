<?php
/** Run production controller against a fake IdP and real MAC verifier. */
declare(strict_types=1);
namespace Symfony\Component\HttpFoundation {
    final class Bag {
        public function __construct(public array $values = []) {}
        public function get(string $k, mixed $fallback=null):mixed{return $this->values[$k]??$fallback;}
        public function has(string $k):bool{return array_key_exists($k,$this->values);}
        public function all():array{return $this->values;}
    }
    final class Headers {
        public array $cookies=[], $removed=[], $values=[];
        public function setCookie(Cookie $cookie):void{$this->cookies[$cookie->name]=$cookie;}
        public function clearCookie(string $name,mixed ...$args):void{$this->removed[]=$name;}
        public function set(string $name,string $value):void{$this->values[$name]=$value;}
    }
    class Request {
        public Bag $attributes,$query,$cookies;
        public function __construct(array $body=[],array $query=[],array $cookies=[],bool $secure=true) {
            $this->attributes=new Bag(['json'=>$body]);$this->query=new Bag($query);$this->cookies=new Bag($cookies);$this->secure=$secure;
        }
        private bool $secure;
        public function isSecure():bool{return $this->secure;}
    }
    class Response {
        public Headers $headers;
        public function __construct(public string $body='',public int $status=200){$this->headers=new Headers();}
        public function getStatusCode():int{return $this->status;}
    }
    final class JsonResponse extends Response {
        public function __construct(public array $data=[],int $status=200,array $headers=[]){parent::__construct('', $status);foreach($headers as $k=>$v)$this->headers->set($k,$v);}
    }
    final class RedirectResponse extends Response {
        public function __construct(public string $target,int $status=303){parent::__construct('', $status);}
        public function getTargetUrl():string{return $this->target;}
    }
    final class Cookie {
        public const SAMESITE_LAX='lax', SAMESITE_STRICT='strict';
        public function __construct(public string $name,public string $value,public string $path,public bool $secure,public bool $httpOnly,public string $sameSite){}
        public static function create(string $name,string $value,mixed $expiry,string $path,mixed $domain,bool $secure,bool $httpOnly,bool $raw,string $sameSite):self{return new self($name,$value,$path,$secure,$httpOnly,$sameSite);}
    }
}
namespace CajeerEngine\Runtime { class ConfigRepository {} }
namespace CajeerEngine\Security { class AuthService {} }
namespace CajeerEngine\Identity {
    class OidcLogin {
        public int $starts=0,$finishes=0;
        public function beginStepUp(string $userId,string $tokenId):array {
            if ($userId!=='owner'||$tokenId!=='token')throw new \RuntimeException('Invalid session');
            ++$this->starts;return ['url'=>'https://id.skif4er.ru/realms/skif4er/protocol/openid-connect/auth?max_age=0','browser'=>str_repeat('a',43)];
        }
        public function finish(string $code,string $state,string $browser,\Symfony\Component\HttpFoundation\Request $request):array {
            if($code!=='verified-code'||$state!=='valid-state'||$browser!==str_repeat('a',43))throw new \RuntimeException('Invalid flow');
            ++$this->finishes;return ['step_up'=>true];
        }
    }
}
namespace {
    use CajeerEngine\Api\Controller\OidcController;
    use CajeerEngine\Forum\Security\ForumOidcReturn;
    use Symfony\Component\HttpFoundation\{Request,JsonResponse,RedirectResponse};
    require dirname(__DIR__).'/core/Forum/Security/ForumOidcReturn.php';
    require dirname(__DIR__).'/core/Api/Controller/OidcController.php';
    $n=0; $check=static function(bool $v,string $name)use(&$n):void{if(!$v)throw new \RuntimeException('FAIL '.$name);++$n;};
    $idp=new \CajeerEngine\Identity\OidcLogin();$controller=new OidcController(new \CajeerEngine\Runtime\ConfigRepository(),$idp);
    $unauthenticated=$controller->stepUpStart(new Request(['return_to'=>'/forum/manage']));
    $check($unauthenticated->status===403,'unauthenticated step-up rejected');
    foreach(['https://evil.example','//evil.example','/admin','/forum/../admin','/forum/%2e%2e/admin','/forum/manage#fragment',[],3] as $bad) {
        $req=new Request(['return_to'=>$bad]);$req->attributes->values['auth']=['cookie'=>true,'session'=>true,'user_id'=>'owner','token_id'=>'token'];
        $check($controller->stepUpStart($req)->status===422&&$idp->starts===0,'unsafe return target rejected');
    }
    $request=new Request(['return_to'=>'/forum/manage?tab=permissions']);
    $request->attributes->values['auth']=['cookie'=>true,'session'=>true,'user_id'=>'owner','token_id'=>'token'];
    $begun=$controller->stepUpStart($request);
    $check($begun instanceof JsonResponse && $begun->status===200 && $idp->starts===1,'live OIDC step-up started for browser session');
    $tx=$begun->headers->cookies['ce_oidc_tx']??null;$return=$begun->headers->cookies['ce_oidc_return']??null;
    $check($tx && $return && $return->secure && $return->httpOnly && $return->path==='/auth/oidc','callback context is secure HttpOnly and scoped');
    $check(ForumOidcReturn::verify($return->value,$tx->value)==='/forum/manage?tab=permissions','return MAC is bound to OIDC browser transaction');
    $callback=new Request([],['code'=>'verified-code','state'=>'valid-state'],['ce_oidc_tx'=>$tx->value,'ce_oidc_return'=>$return->value]);
    $result=$controller->callback($callback);
    $check($result instanceof RedirectResponse && $result->target==='/forum/manage?tab=permissions','SSO MFA returns to original forum page');
    $check(!isset($result->headers->cookies['ce_session']) && in_array('ce_oidc_return',$result->headers->removed,true),'step-up never issues replacement login and clears browser context');
    $tampered=new Request([],['code'=>'verified-code','state'=>'valid-state'],['ce_oidc_tx'=>$tx->value,'ce_oidc_return'=>str_repeat('x',8).$return->value]);
    $check($controller->callback($tampered)->target==='/admin/#/identity','tampered return context cannot redirect to forum');
    $denied=$controller->callback(new Request([],['error'=>'access_denied'],['ce_oidc_tx'=>$tx->value,'ce_oidc_return'=>$return->value]));
    $check($denied->target==='/forum/login?sso_error=cancelled','IdP denial cannot report successful MFA');
    $admin=new Request();$admin->attributes->values['auth']=['cookie'=>true,'session'=>true,'user_id'=>'owner','token_id'=>'token'];
    $beginAdmin=$controller->stepUpStart($admin);
    $check(in_array('ce_oidc_return',$beginAdmin->headers->removed,true),'admin step-up explicitly clears previous forum context');
    $check($controller->callback(new Request([],['code'=>'verified-code','state'=>'valid-state'],['ce_oidc_tx'=>$beginAdmin->headers->cookies['ce_oidc_tx']->value]))->target==='/admin/#/identity','admin callback destination preserved');
    echo "Forum 1.0.8 OIDC continuation: $n PASS\n";
}
