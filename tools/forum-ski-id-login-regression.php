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
namespace CajeerEngine\Runtime { class ConfigRepository {} }
namespace CajeerEngine\Security { class AuthService {} }
namespace CajeerEngine\Identity {
    class OidcLogin {
        public string $failure = '';
        public function begin(): array { return ['url'=>'https://id.skif4er.ru/realms/skif4er/protocol/openid-connect/auth?state=abc', 'browser'=>str_repeat('b',43)]; }
        public function finish(string $code, string $state, string $browser, \Symfony\Component\HttpFoundation\Request $request): array {
            if ($this->failure !== '') throw new \RuntimeException($this->failure);
            if ($code !== 'valid' || $state !== 'valid-state' || $browser !== str_repeat('b',43)) throw new \RuntimeException('Invalid transaction');
            return ['access_token'=>'ce_test_opaque', 'expires_at'=>'+1 hour'];
        }
    }
}
namespace {
    use CajeerEngine\Api\Controller\OidcController;
    use CajeerEngine\Forum\Security\ForumOidcReturn;
    use Symfony\Component\HttpFoundation\{Request,RedirectResponse,JsonResponse};
    require_once dirname(__DIR__).'/core/Forum/Security/ForumOidcReturn.php';
    require_once dirname(__DIR__).'/core/Api/Controller/OidcController.php';
    $n=0;
    function check(bool $condition, string $message): void { global $n; if (!$condition) throw new \RuntimeException('FAIL '.$message); echo 'PASS '.$message."\n"; $n++; }
    $login = new \CajeerEngine\Identity\OidcLogin();
    $controller = new OidcController(new \CajeerEngine\Runtime\ConfigRepository(), $login);
    $target='/forum/threads/12345678-1234-1234-1234-123456789abc?page=2';
    foreach (['https://evil.example/','//evil.example','/admin','/forumx','/forum//evil','/forum/../admin','/forum/%2e%2e/admin','/forum/#a','/forum\\evil','/forum/'.str_repeat('x',1100)] as $unsafe) {
        check(ForumOidcReturn::sanitize($unsafe) === null, 'reject unsafe continuation '.substr($unsafe,0,35));
    }
    check(ForumOidcReturn::sanitize($target)===$target, 'allow bounded forum thread and query continuation');
    $invalid=$controller->start(new Request(['return_to'=>'https://evil.example']));
    check($invalid instanceof JsonResponse && $invalid->status === 422, 'reject unsafe return before OIDC transaction');
    $insecure=$controller->start(new Request(['return_to'=>'/forum'], [], false));
    check($insecure->status===403, 'reject HTTP login');
    $started=$controller->start(new Request(['return_to'=>$target]));
    check($started instanceof RedirectResponse && $started->status===302 && str_starts_with($started->target,'https://id.skif4er.ru/'), 'real browser redirect to configured IdP');
    check(isset($started->headers->cookies['ce_oidc_return'],$started->headers->cookies['ce_oidc_tx']), 'bind continuation to browser OIDC transaction');
    foreach (['ce_oidc_tx','ce_oidc_return'] as $cookie) {
        $value=$started->headers->cookies[$cookie];
        check($value->path==='/auth/oidc' && $value->secure && $value->httpOnly && $value->sameSite==='lax', 'secure browser transaction cookie '.$cookie);
    }
    $cookies=array_map(static fn($v)=>$v->value,$started->headers->cookies);
    check(ForumOidcReturn::verify($cookies['ce_oidc_return'],$cookies['ce_oidc_tx'])===$target, 'signed return context validates');
    check(ForumOidcReturn::verify($cookies['ce_oidc_return'],'another-browser')===null, 'cannot replay return against another browser transaction');
    check(ForumOidcReturn::verify($cookies['ce_oidc_return'].'x',$cookies['ce_oidc_tx'])===null, 'reject tampered return cookie');
    $completed=$controller->callback(new Request(['code'=>'valid','state'=>'valid-state'],$cookies));
    check($completed instanceof RedirectResponse && $completed->target===$target && $completed->status===303, 'successful callback returns to original forum thread');
    $session=$completed->headers->cookies['ce_session']??null;
    check($session!==null && $session->value==='ce_test_opaque' && $session->path==='/api/v1' && $session->secure && $session->httpOnly && $session->sameSite==='strict', 'login issues existing protected API browser session');
    check(in_array('ce_oidc_tx',$completed->headers->deleted,true) && in_array('ce_oidc_return',$completed->headers->deleted,true), 'consumed browser context removed');
    $login->failure='This SkiF4er ID is not linked to a local account.';
    $unlinked=$controller->callback(new Request(['code'=>'valid','state'=>'valid-state'],$cookies));
    check($unlinked instanceof RedirectResponse && $unlinked->target==='/forum/login?sso_error=not_linked' && !isset($unlinked->headers->cookies['ce_session']), 'unlinked identity does not create session');
    $login->failure='';
    $denied=$controller->callback(new Request(['error'=>'access_denied'],$cookies));
    check($denied->target==='/forum/login?sso_error=cancelled', 'provider cancellation handled on forum');
    $bad=$controller->callback(new Request(['code'=>'valid','state'=>'wrong-state'],$cookies));
    check($bad->target==='/forum/login?sso_error=login_failed' && !isset($bad->headers->cookies['ce_session']), 'invalid OIDC transaction fails closed');
    $admin=$controller->start(new Request());
    check(in_array('ce_oidc_return',$admin->headers->deleted,true), 'admin login clears stale forum return intent');
    $adminCb=$controller->callback(new Request(['code'=>'valid','state'=>'valid-state'],['ce_oidc_tx'=>$cookies['ce_oidc_tx']]));
    check($adminCb->target==='/admin/' && isset($adminCb->headers->cookies['ce_session']), 'admin login destination remains unchanged');
    $disabled=(new OidcController(new \CajeerEngine\Runtime\ConfigRepository(), null))->start(new Request(['return_to'=>'/forum']));
    check($disabled instanceof JsonResponse && $disabled->status===503, 'disabled OIDC remains closed');
    echo "Forum 1.0.3 SkiF4er ID login: $n checks passed\n";
}
