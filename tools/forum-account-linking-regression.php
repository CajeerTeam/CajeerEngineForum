<?php
/** Forum 1.0.4: exercise actual OIDC controller with in-memory HTTP/IdP/session doubles. */
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
        public Bag $query, $cookies, $attributes;
        public function __construct(array $query = [], array $cookies = [], private bool $secure = true, array $attributes = []) {
            $this->query = new Bag($query); $this->cookies = new Bag($cookies); $this->attributes = new Bag($attributes);
        }
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
        public function __construct(public array $data = [], int $status = 200, array $headers = []) {
            parent::__construct('', $status); foreach ($headers as $k => $v) $this->headers->set($k, $v);
        }
    }
    final class Cookie {
        public const SAMESITE_LAX = 'lax', SAMESITE_STRICT = 'strict';
        public function __construct(public string $name, public string $value, public string $path, public bool $secure, public bool $httpOnly, public string $sameSite) {}
        public static function create(string $name, string $value, mixed $expiry, string $path, mixed $domain, bool $secure, bool $httpOnly, bool $raw, string $sameSite): self {
            return new self($name, $value, $path, $secure, $httpOnly, $sameSite);
        }
    }
}
namespace CajeerEngine\Runtime { class ConfigRepository {} }
namespace CajeerEngine\Security {
    class AuthService {
        public array $reauth = [];
        public function confirmSensitiveAction(array $identity, string $password, ?string $code): bool {
            $this->reauth[] = [$identity['user_id'], $password, $code];
            return $password === 'valid-local-password' && $code === '123456';
        }
    }
}
namespace CajeerEngine\Identity {
    class OidcLogin {
        public bool $linked = false, $provisioned = false, $ssoSession = false, $failFinish = false, $failUnlink = false;
        public int $beginCount = 0, $unlinks = 0;
        public function accountStatus(string $userId): array { return ['linked'=>$this->linked, 'provisioned'=>$this->provisioned, 'created_at'=>$this->linked?'2026-11-22 09:00:00':null]; }
        public function begin(?string $userId = null, ?string $token = null): array {
            if ($userId === null || $token === null) throw new \RuntimeException('Expected authenticated link context');
            $this->beginCount++;
            return ['url'=>'https://id.skif4er.ru/realms/skif4er/protocol/openid-connect/auth?state=s&code_challenge=c&code_challenge_method=S256', 'browser'=>str_repeat('b', 43)];
        }
        public function finish(string $code, string $state, string $browser, \Symfony\Component\HttpFoundation\Request $request): array {
            if ($this->failFinish || $code !== 'ok' || $state !== 'state' || $browser !== str_repeat('b',43)) throw new \RuntimeException('Link denied');
            $this->linked = true; return ['linked'=>true];
        }
        public function isSsoSession(string $userId, string $tokenId): bool { return $this->ssoSession; }
        public function unlink(string $userId): bool {
            if ($this->failUnlink) throw new \RuntimeException('Database SELECT secrets: must-not-leak');
            if ($this->provisioned) throw new \RuntimeException('Set up a verified local login before unlinking a provisioned account.');
            $this->unlinks++;
            if (!$this->linked) return false;
            $this->linked = false; return true;
        }
    }
}
namespace {
    use CajeerEngine\Api\Controller\OidcController;
    use CajeerEngine\Forum\Security\ForumOidcReturn;
    use Symfony\Component\HttpFoundation\{Request,JsonResponse,RedirectResponse};
    require_once dirname(__DIR__).'/core/Forum/Security/ForumOidcReturn.php';
    require_once dirname(__DIR__).'/core/Api/Controller/OidcController.php';
    $count=0;
    function check(bool $ok, string $name): void {
        global $count; if (!$ok) throw new \RuntimeException('FAIL '.$name);
        echo 'PASS '.$name."\n"; $count++;
    }
    $user = ['user_id'=>'11111111-1111-1111-1111-111111111111','token_id'=>'22222222-2222-2222-2222-222222222222','cookie'=>true,'session'=>true];
    $payload = ['password'=>'valid-local-password','two_factor_code'=>'123456','return_to'=>'/forum/account/security'];
    $request = static fn(array $data = [], bool $secure = true, ?array $actor = null): Request =>
        new Request([],[], $secure, ['auth' => $actor ?? $user, 'json' => $data]);
    $login=new \CajeerEngine\Identity\OidcLogin();
    $auth=new \CajeerEngine\Security\AuthService();
    $controller=new OidcController(new \CajeerEngine\Runtime\ConfigRepository(), $login, $auth);
    $status=$controller->account($request());
    check($status->status===200 && $status->data['data']['enabled'] && $status->data['data']['linked']===false, 'account status from live session');
    check($controller->account(new Request())->status===403,'unauthenticated status forbidden');
    check($controller->linkStart($request($payload, false))->status===403, 'HTTPS mandatory for linking');
    check($controller->linkStart($request([]))->status===422,'password required');
    check($controller->linkStart($request(['password'=>'valid-local-password','two_factor_code'=>'123456','return_to'=>'//evil.example']))->status===422,'external return address refused');
    check($controller->linkStart($request(['password'=>'bad','two_factor_code'=>'123456']))->status===403,'invalid password refused');
    check($controller->linkStart($request(['password'=>'valid-local-password','two_factor_code'=>'bad']))->status===403,'invalid 2FA refused');
    check($login->beginCount===0,'failed sensitive confirmation cannot begin OIDC transaction');
    check($controller->linkStart($request($payload, true, ['user_id'=>$user['user_id'],'token_id'=>$user['token_id'],'cookie'=>false,'session'=>true]))->status===403,'bearer token cannot start account link');
    $start=$controller->linkStart($request($payload));
    check($start instanceof JsonResponse && $start->status===200 && str_starts_with($start->data['data']['authorization_url'],'https://id.skif4er.ru/'), 'verified account starts real IdP redirect');
    check($login->beginCount===1 && $auth->reauth[0][0]===$user['user_id'],'transaction bound to reauthenticated local UUID');
    $tx=$start->headers->cookies['ce_oidc_tx']??null;
    $ret=$start->headers->cookies['ce_oidc_return']??null;
    check($tx !== null && $ret !== null && $tx->secure && $ret->httpOnly && $tx->path==='/auth/oidc' && $ret->sameSite==='lax','browser-bound Secure HttpOnly OIDC cookies');
    check(ForumOidcReturn::verify($ret->value,$tx->value)==='/forum/account/security','return destination signed against one-time browser secret');
    check(ForumOidcReturn::verify($ret->value,'wrong browser')===null,'return destination rejects another browser');
    $cb=$controller->callback(new Request(['code'=>'ok','state'=>'state'],['ce_oidc_tx'=>$tx->value,'ce_oidc_return'=>$ret->value]));
    check($cb instanceof RedirectResponse && $cb->target==='/forum/account/security?identity=linked', 'completed link returns to forum security with success notice');
    check(!isset($cb->headers->cookies['ce_session']) && count($cb->headers->deleted)===2,'link callback does not replace local session and clears transaction cookies');
    check($controller->linkStart($request($payload))->status===409,'existing link cannot be overwritten');
    $login->linked=false;
    $login->failFinish=true;
    $cb=$controller->callback(new Request(['code'=>'ok','state'=>'state'],['ce_oidc_tx'=>$tx->value,'ce_oidc_return'=>$ret->value]));
    check($cb->target==='/forum/account/security?identity_error=link_failed' && !isset($cb->headers->cookies['ce_session']),'failed link returns to safe forum page without session token');
    $login->failFinish=false;
    $cb=$controller->callback(new Request(['error'=>'access_denied'],['ce_oidc_tx'=>$tx->value,'ce_oidc_return'=>$ret->value]));
    check($cb->target==='/forum/account/security?identity_error=cancelled','provider cancellation returns to account security');
    $login->linked=true;
    check($controller->unlink($request(['password'=>'bad','two_factor_code'=>'123456']))->status===403,'wrong local password cannot unlink');
    check($controller->unlink($request(['password'=>'valid-local-password','two_factor_code'=>'123456'],false))->status===403,'HTTP cannot unlink');
    $login->provisioned=true;
    check($controller->unlink($request($payload))->status===409,'OIDC-only user cannot remove sole login method');
    $login->provisioned=false;
    $login->failUnlink=true;
    $fault=$controller->unlink($request($payload));
    check($fault->status===503 && $fault->data['error']['code']==='unlink_failed'
        && !str_contains(json_encode($fault->data), 'must-not-leak'), 'database failures never expose SQL or internal state');
    $login->failUnlink=false;
    $login->ssoSession=true;
    $done=$controller->unlink($request($payload));
    check($done->status===200 && $done->data['data']['unlinked']===true && $done->data['data']['session_revoked']===true,'unlink revokes current SSO session');
    check(in_array('ce_session',$done->headers->deleted,true),'revoked SSO browser cookie cleared');
    $login->linked=true;$login->ssoSession=false;
    $done=$controller->unlink($request($payload));
    check($done->status===200 && $done->data['data']['unlinked']===true && $done->data['data']['session_revoked']===false,'local session survives external unlink');
    check(!in_array('ce_session',$done->headers->deleted,true),'local browser cookie retained');
    check($login->unlinks===2,'only authenticated unlink operations changed mapping');
    // Calling the existing admin flow must not set a forum return cookie.
    $start=$controller->linkStart($request(['password'=>'valid-local-password','two_factor_code'=>'123456']));
    check($start->status===200 && !isset($start->headers->cookies['ce_oidc_return']) && in_array('ce_oidc_return',$start->headers->deleted,true),'admin account-link flow remains unchanged');
    echo 'Forum Account Linking 1.0.4: '.$count." PASS\n";
}
