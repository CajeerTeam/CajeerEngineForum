<?php
/** Executable Forum 1.0.3 OIDC flow regression with isolated HTTP transport doubles. */
declare(strict_types=1);

namespace Symfony\Component\HttpFoundation {
    final class Bag {
        public function __construct(public array $values = []) {}
        public function get(string $key): mixed { return $this->values[$key] ?? null; }
        public function has(string $key): bool { return array_key_exists($key, $this->values); }
        public function all(): array { return $this->values; }
    }
    final class Headers {
        public array $cookies = [], $deleted = [], $headers = [];
        public function setCookie(Cookie $cookie): void { $this->cookies[$cookie->name] = $cookie; }
        public function clearCookie(string $name, mixed ...$args): void { $this->deleted[] = $name; }
        public function set(string $name, string $value): void { $this->headers[$name] = $value; }
    }
    class Request {
        public Bag $query, $cookies;
        public function __construct(array $query = [], array $cookies = [], private bool $secure = true) { $this->query = new Bag($query); $this->cookies = new Bag($cookies); }
        public function isSecure(): bool { return $this->secure; }
    }
    class Response {
        public Headers $headers;
        public function __construct(public mixed $content = '', public int $status = 200) { $this->headers = new Headers(); }
        public function getStatusCode(): int { return $this->status; }
    }
    final class RedirectResponse extends Response {
        public function __construct(public string $target, int $status = 302) { parent::__construct('', $status); }
        public function getTargetUrl(): string { return $this->target; }
    }
    final class JsonResponse extends Response {
        public function __construct(public array $data = [], int $status = 200, array $headers = []) { parent::__construct('', $status); foreach ($headers as $k=>$v) $this->headers->set($k,$v); }
    }
    final class Cookie {
        public const SAMESITE_LAX = 'lax', SAMESITE_STRICT = 'strict';
        public function __construct(public string $name, public string $value, public string $path, public bool $secure, public bool $httpOnly, public string $sameSite) {}
        public static function create(string $name, string $value, mixed $expiry, string $path, mixed $domain, bool $secure, bool $httpOnly, bool $raw, string $sameSite): self { return new self($name,$value,$path,$secure,$httpOnly,$sameSite); }
    }
}
namespace CajeerEngine\Runtime { class ConfigRepository {public function string(string $key, string $default=""):string {return "https://forum.example.test";} } }
namespace CajeerEngine\Security { class AuthService {} }
namespace CajeerEngine\Identity {
 class OidcLogin {
     public int $calls = 0;
     public bool $allow = true;
     public function globalLogout(\Symfony\Component\HttpFoundation\Request $request, string $url, ?string $state = null): string {
         if (!$this->allow) throw new \RuntimeException('Browser OIDC session required');
         if ($url !== 'https://forum.example.test/auth/oidc/logout/complete' || !is_string($state) || preg_match('/^[a-f0-9]{48}$/D',$state)!==1)throw new \RuntimeException('Invalid RP logout request');
         ++$this->calls;
         return 'https://id.skif4er.ru/realms/skif4er/protocol/openid-connect/logout?client_id=forum&state='.$state;
     }
 }
}
namespace {
 use CajeerEngine\Api\Controller\OidcController;
 use Symfony\Component\HttpFoundation\{Request,RedirectResponse,JsonResponse};
 require_once dirname(__DIR__).'/core/Api/Controller/OidcController.php';
 $checks=0;
 $check=static function(bool $result,string $label) use (&$checks):void {if(!$result)throw new \RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";};
 $oidc=new \CajeerEngine\Identity\OidcLogin();
 $controller=new OidcController(new \CajeerEngine\Runtime\ConfigRepository(),$oidc);
 foreach(['/forum','https://evil.test','//evil.test','/forum/login?next=/admin', '/admin', 1, ['foo']] as $bad) {
   $request=new Request(['return_to'=>$bad]);
   $reply=$controller->globalLogout($request);
   $check($reply instanceof JsonResponse && $reply->status===422 && $oidc->calls===0,'reject non-allowlisted forum logout return');
 }
 $check($controller->globalLogout(new Request([],[],false))->status===403,'HTTPS mandatory');
 $oidc->allow=false;
 $denied=$controller->globalLogout(new Request(['return_to'=>'/forum/login']));
 $check($denied->status===409 && empty($denied->headers->cookies),'global logout fails closed for local/bearer session');
 $oidc->allow=true;
 $started=$controller->globalLogout(new Request(['return_to'=>'/forum/login']));
 $check($started instanceof JsonResponse && $started->status===200 && $started->data['data']['local_session_revoked']===true,'live OIDC flow starts and revokes local browser session');
 $url=$started->data['data']['authorization_url'];
 parse_str(parse_url($url,PHP_URL_QUERY),$params);
 $check(preg_match('/^[a-f0-9]{48}$/D',$params['state']??'')===1,'random RP logout state passed to provider');
 $cookie=$started->headers->cookies['ce_oidc_logout_state']??null;
 $check($cookie && $cookie->value==='forum.'.$params['state'] && $cookie->path==='/auth/oidc/logout/complete' && $cookie->secure && $cookie->httpOnly && $cookie->sameSite==='lax','secure HttpOnly browser context is bound to IdP logout state');
 $check(in_array('ce_session',$started->headers->deleted,true),'revoked browser cookie removed');
 $done=$controller->logoutComplete(new Request(['state'=>$params['state']],['ce_oidc_logout_state'=>$cookie->value]));
 $check($done instanceof RedirectResponse && $done->target==='/forum/login?logout=skif4er' && in_array('ce_oidc_logout_state',$done->headers->deleted,true),'confirmed IdP callback returns to forum and deletes browser context');
 $error=$controller->logoutComplete(new Request(['state'=>$params['state'],'error'=>'access_denied'],['ce_oidc_logout_state'=>$cookie->value]));
 $check($error->target==='/forum/login?logout=unconfirmed','IdP errors must never claim confirmed global sign-out');
 foreach (['bad',null,['state'],$params['state'].'extra'] as $state) {
   $req=new Request($state===null?[]:['state'=>$state],['ce_oidc_logout_state'=>$cookie->value]);
   $check($controller->logoutComplete($req)->target==='/admin/#/login','wrong/missing state must not claim IdP completion');
 }
 $check($controller->logoutComplete(new Request(['state'=>$params['state']]))->target==='/admin/#/login','wrong browser cannot reuse logout state');
 $admin=$controller->globalLogout(new Request());
 $check($admin->status===200 && str_starts_with($admin->headers->cookies['ce_oidc_logout_state']->value,'admin.'),'existing admin logout still supported');
 parse_str(parse_url($admin->data['data']['authorization_url'],PHP_URL_QUERY),$adminParams);
 $check($controller->logoutComplete(new Request(['state'=>$adminParams['state']],['ce_oidc_logout_state'=>$admin->headers->cookies['ce_oidc_logout_state']->value]))->target==='/admin/#/login','admin callback destination unchanged');
 echo "Forum 1.0.7 SSO logout: $checks PASS\n";
}
