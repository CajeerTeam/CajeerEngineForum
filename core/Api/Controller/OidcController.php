<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Identity\OidcLogin;
use CajeerEngine\Forum\Security\ForumOidcReturn;
use CajeerEngine\Security\AuthService;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Browser redirects only; ID/access/refresh tokens are never emitted into a response. */
final readonly class OidcController
{
    public function __construct(private ConfigRepository $config, private ?OidcLogin $login, private ?AuthService $auth = null) {}

    public function status(Request $request): JsonResponse
    {
        return new JsonResponse(['data'=>['enabled'=>$this->login !== null, 'provider'=>'SkiF4er ID', 'global_logout'=>$this->login !== null, 'auto_provision'=>$this->login?->canAutoProvision() ?? false, 'session_strict'=>filter_var(getenv('IDENTITY_SESSION_STRICT') ?: 'false', FILTER_VALIDATE_BOOLEAN)]], 200, ['Cache-Control'=>'no-store']);
    }

    public function start(Request $request): Response
    {
        if ($this->login === null) return new JsonResponse(['error'=>['code'=>'oidc_disabled','message'=>'SkiF4er ID не настроен.']], 503);
        if (!$request->isSecure()) return new JsonResponse(['error'=>['code'=>'https_required','message'=>'OIDC требует HTTPS.']], 403);
        // The return destination is optional; without it the admin login retains
        // its original behaviour. Never persist arbitrary caller-controlled URLs.
        $raw = $request->query->all()['return_to'] ?? null;
        $target = $raw === null ? null : ForumOidcReturn::sanitize($raw);
        if ($raw !== null && $target === null) {
            return new JsonResponse(['error'=>['code'=>'invalid_return','message'=>'Недопустимый адрес возврата на форум.']], 422, ['Cache-Control'=>'no-store']);
        }
        try {
            $login = $this->login->begin();
            $response = new RedirectResponse($login['url'], 302);
            $expiry = new \DateTimeImmutable('+5 minutes');
            $response->headers->setCookie(Cookie::create('ce_oidc_tx', $login['browser'], $expiry, '/auth/oidc', null, true, true, false, Cookie::SAMESITE_LAX));
            if ($target !== null) {
                $response->headers->setCookie(Cookie::create('ce_oidc_return', ForumOidcReturn::sign($target, $login['browser']), $expiry, '/auth/oidc', null, true, true, false, Cookie::SAMESITE_LAX));
            } else {
                $response->headers->clearCookie('ce_oidc_return', '/auth/oidc', null, true, true, Cookie::SAMESITE_LAX);
            }
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            return $response;
        } catch (\Throwable) {
            if ($target !== null) {
                $response = new RedirectResponse('/forum/login?sso_error=provider_error', 303);
                $response->headers->set('Cache-Control', 'no-store');
                $response->headers->set('Referrer-Policy', 'no-referrer');
                return $response;
            }
            return new JsonResponse(['error'=>['code'=>'oidc_unavailable','message'=>'Не удалось начать авторизацию SkiF4er ID.']], 503);
        }
    }

    public function callback(Request $request): Response
    {
        $browser = $request->cookies->get('ce_oidc_tx');
        $target = ForumOidcReturn::verify($request->cookies->get('ce_oidc_return'), $browser);
        $forumLink = $target === '/forum/account/security';
        $fallback = $target !== null ? '/forum/login' : '/admin/#/login';
        $failure = $forumLink ? '/forum/account/security?identity_error=' : '/forum/login?sso_error=';
        $response = new RedirectResponse($fallback, 303);
        // Always clear transaction and return cookies, including provider denial,
        // state mismatch and transport faults. Neither cookie is a login session.
        $clean = static function (Response $result): Response {
            $result->headers->clearCookie('ce_oidc_tx', '/auth/oidc', null, true, true, Cookie::SAMESITE_LAX);
            $result->headers->clearCookie('ce_oidc_return', '/auth/oidc', null, true, true, Cookie::SAMESITE_LAX);
            $result->headers->set('Cache-Control', 'no-store');
            $result->headers->set('Referrer-Policy', 'no-referrer');
            return $result;
        };
        if ($this->login === null || !$request->isSecure()) return $clean($response);
        if ($request->query->has('error')) {
            $denied = ($request->query->all()['error'] ?? null) === 'access_denied';
            return $clean(new RedirectResponse($target !== null ? $failure.($denied ? 'cancelled' : 'provider_error') : $fallback, 303));
        }
        $code = $request->query->all()['code'] ?? null;
        $state = $request->query->all()['state'] ?? null;
        if (!is_string($code) || !is_string($state) || !is_string($browser)) {
            return $clean(new RedirectResponse($target !== null ? $failure.'invalid_callback' : $fallback, 303));
        }
        try {
            $session = $this->login->finish($code, $state, $browser, $request);
            if (($session['linked'] ?? false) === true || ($session['step_up'] ?? false) === true) {
                $destination = $forumLink && ($session['linked'] ?? false) === true
                    ? '/forum/account/security?identity=linked'
                    : ($target !== null ? '/forum/account/security' : '/admin/#/identity');
            } else {
                $destination = $target ?? '/admin/';
                if (($session['provisioned'] ?? false) === true && $target !== null) {
                    $destination = '/forum/account?created=skif4er';
                }
            }
            $response = new RedirectResponse($destination, 303);
            if (($session['linked'] ?? false) !== true && ($session['step_up'] ?? false) !== true) {
                $response->headers->setCookie(Cookie::create('ce_session', $session['access_token'], new \DateTimeImmutable((string)$session['expires_at']), '/api/v1', null, true, true, false, Cookie::SAMESITE_STRICT));
            }
        } catch (\Throwable $error) {
            // Deliberately avoid echoing provider/transport/internal errors to the browser.
            $code = ($error instanceof \CajeerEngine\Forum\ForumException && $error->getMessage() === 'Регистрация на форуме отключена.') ? 'registration_closed'
                : ($error instanceof \CajeerEngine\Forum\ForumException && $error->status === 409 ? 'email_exists'
                : (str_contains($error->getMessage(), 'not linked to a local account') ? 'not_linked'
                : (str_contains($error->getMessage(), 'multi-factor authentication evidence') ? 'mfa_required' : 'login_failed')));
            $response = new RedirectResponse($target !== null ? $failure.($forumLink ? 'link_failed' : $code) : $fallback, 303);
        }
        return $clean($response);
    }

    /** Authenticated, CSRF-protected browser POST; no logout on a GET or from a Bearer token. */
    public function globalLogout(Request $request): Response
    {
        if ($this->login === null) return new JsonResponse(['error'=>['code'=>'oidc_disabled','message'=>'SkiF4er ID отключён.']], 503);
        if (!$request->isSecure()) return new JsonResponse(['error'=>['code'=>'https_required','message'=>'Требуется HTTPS.']], 403);
        try {
            $base = rtrim($this->config->string('app.url'), '/');
            $url = $this->login->globalLogout($request, $base.'/auth/oidc/logout/complete');
            $response = new JsonResponse(['data'=>['authorization_url'=>$url, 'local_session_revoked'=>true]], 200, ['Cache-Control'=>'no-store']);
            $response->headers->clearCookie('ce_session', '/api/v1', null, true, true, Cookie::SAMESITE_STRICT);
            return $response;
        } catch (\Throwable) {
            return new JsonResponse(['error'=>['code'=>'global_logout_failed','message'=>'Глобальный выход не выполнен. Проверьте подключение и активную сессию SkiF4er ID.']], 409, ['Cache-Control'=>'no-store']);
        }
    }

    /** Keycloak sends application/x-www-form-urlencoded logout_token, not a browser cookie. */
    public function backchannel(Request $request): JsonResponse
    {
        $headers = ['Cache-Control'=>'no-store'];
        if ($this->login === null || !$request->isSecure()) return new JsonResponse(['error'=>'unavailable'], 403, $headers);
        $type = strtolower((string)$request->headers->get('Content-Type',''));
        if (!str_starts_with($type, 'application/x-www-form-urlencoded') || strlen($request->getContent()) > 20000) {
            return new JsonResponse(['error'=>'invalid_request'], 400, $headers);
        }
        $token = $request->request->get('logout_token');
        if (!is_string($token) || $token === '' || strlen($token) > 16384) return new JsonResponse(['error'=>'invalid_request'], 400, $headers);
        try { $claims = $this->login->verifyBackchannel($token); }
        catch (\Throwable) { return new JsonResponse(['error'=>'invalid_logout_token'], 400, $headers); }
        try {
            $this->login->applyVerifiedBackchannel($claims);
            return new JsonResponse([], 200, $headers);
        } catch (\Throwable) {
            // A database fault must not ACK receipt; Keycloak may retry delivery.
            return new JsonResponse(['error'=>'temporarily_unavailable'], 503, $headers);
        }
    }

    /** This callback has no side effects. It is safe even if the provider returns an error. */
    public function logoutComplete(Request $request): RedirectResponse
    {
        $response = new RedirectResponse('/admin/#/login', 303);
        $response->headers->set('Cache-Control','no-store');
        $response->headers->set('Referrer-Policy','no-referrer');
        return $response;
    }

    /** Never accepts bearer credentials; cookie + CSRF are enforced by auth middleware. */
    public function stepUpStart(Request $request): JsonResponse
    {
        $identity = $this->currentUser($request);
        if ($identity === null || $this->login === null || !$request->isSecure()) {
            return $this->linkError('step_up_unavailable', 'Требуется HTTPS и действующая OIDC-сессия.', 403);
        }
        try {
            $tx = $this->login->beginStepUp((string)$identity['user_id'], (string)$identity['token_id']);
            $response = new JsonResponse(['data'=>['authorization_url'=>$tx['url']]], 200, ['Cache-Control'=>'no-store']);
            $response->headers->setCookie(Cookie::create('ce_oidc_tx', $tx['browser'], new \DateTimeImmutable('+5 minutes'), '/auth/oidc', null, true, true, false, Cookie::SAMESITE_LAX));
            return $response;
        } catch (\Throwable) { return $this->linkError('step_up_unavailable', 'Повторная авторизация SkiF4er ID недоступна.', 409); }
    }

    public function account(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($user === null) return new JsonResponse(['error'=>['code'=>'session_required','message'=>'Требуется защищённая браузерная сессия.']], 403);
        if ($this->login === null) return new JsonResponse(['data'=>['enabled'=>false, 'linked'=>false]], 200, ['Cache-Control'=>'no-store']);
        return new JsonResponse(['data'=>['enabled'=>true] + $this->login->accountStatus((string)$user['user_id'])], 200, ['Cache-Control'=>'no-store']);
    }

    /** Recent local password + 2FA confirmation and CSRF are required before contacting the IdP. */
    public function linkStart(Request $request): Response
    {
        $identity = $this->currentUser($request);
        if ($identity === null || $this->login === null || $this->auth === null || !$request->isSecure()) {
            return $this->linkError('link_unavailable', 'Привязка требует HTTPS, активную сессию и настроенный SkiF4er ID.', 403);
        }
        $payload = $request->attributes->get('json');
        if (!is_array($payload) || !is_string($payload['password'] ?? null) || strlen($payload['password']) > 1024) return $this->linkError('validation_failed', 'Введите текущий пароль.', 422);
        // The only allowed forum continuation is the account security page.
        // Existing ACP callers omit this field and retain their original redirect.
        $returnTo = $payload['return_to'] ?? null;
        if ($returnTo !== null && $returnTo !== '/forum/account/security') {
            return $this->linkError('invalid_return', 'Недопустимый адрес завершения привязки.', 422);
        }
        $code = $payload['two_factor_code'] ?? null;
        if (!is_null($code) && (!is_string($code) || strlen($code) > 128)) return $this->linkError('validation_failed', 'Некорректный 2FA код.', 422);
        if (!$this->auth->confirmSensitiveAction($identity, $payload['password'], $code)) return $this->linkError('reauthentication_required', 'Неверный пароль или код 2FA.', 403);
        try {
            if ($this->login->accountStatus((string)$identity['user_id'])['linked']) return $this->linkError('already_linked', 'SkiF4er ID уже привязан.', 409);
            $tx = $this->login->begin((string)$identity['user_id'], (string)$identity['token_id']);
            $response = new JsonResponse(['data'=>['authorization_url'=>$tx['url']]], 200, ['Cache-Control'=>'no-store']);
            $expiry = new \DateTimeImmutable('+5 minutes');
            $response->headers->setCookie(Cookie::create('ce_oidc_tx', $tx['browser'], $expiry, '/auth/oidc', null, true, true, false, Cookie::SAMESITE_LAX));
            if ($returnTo !== null) {
                $response->headers->setCookie(Cookie::create('ce_oidc_return', ForumOidcReturn::sign($returnTo, $tx['browser']), $expiry, '/auth/oidc', null, true, true, false, Cookie::SAMESITE_LAX));
            } else {
                $response->headers->clearCookie('ce_oidc_return', '/auth/oidc', null, true, true, Cookie::SAMESITE_LAX);
            }
            $response->headers->set('Referrer-Policy', 'no-referrer');
            return $response;
        } catch (\Throwable) { return $this->linkError('link_failed', 'Не удалось начать безопасную привязку.', 409); }
    }

    public function unlink(Request $request): JsonResponse
    {
        $identity = $this->currentUser($request);
        if ($identity === null || $this->login === null || $this->auth === null || !$request->isSecure()) {
            return $this->linkError('unlink_unavailable', 'Для отвязки необходима защищённая браузерная сессия.', 403);
        }
        $payload = $request->attributes->get('json');
        if (!is_array($payload) || !is_string($payload['password'] ?? null) || strlen($payload['password']) > 1024) return $this->linkError('validation_failed', 'Введите текущий пароль.', 422);
        $code = $payload['two_factor_code'] ?? null;
        if (!is_null($code) && (!is_string($code) || strlen($code) > 128)) return $this->linkError('validation_failed', 'Некорректный 2FA код.', 422);
        if (!$this->auth->confirmSensitiveAction($identity, $payload['password'], $code)) return $this->linkError('reauthentication_required', 'Неверный пароль или код 2FA.', 403);
        try {
            $uid = (string)$identity['user_id'];
            $currentWasSso = $this->login->isSsoSession($uid, (string)$identity['token_id']);
            $unlinked = $this->login->unlink($uid);
            $sessionRevoked = $unlinked && $currentWasSso;
            $response = new JsonResponse(['data'=>['unlinked'=>$unlinked, 'session_revoked'=>$sessionRevoked]], 200, ['Cache-Control'=>'no-store']);
            if ($sessionRevoked) $response->headers->clearCookie('ce_session', '/api/v1', null, true, true, Cookie::SAMESITE_STRICT);
            return $response;
        } catch (\RuntimeException $error) {
            if ($error->getMessage() === 'Set up a verified local login before unlinking a provisioned account.') {
                return $this->linkError('unlink_denied', 'Нельзя отвязать единственный способ входа. Сначала настройте подтверждённый локальный вход.', 409);
            }
            // PDO exceptions must not disclose SQL, schema details or internal state.
            return $this->linkError('unlink_failed', 'Отвязка не завершена. Повторите попытку позже.', 503);
        }
    }

    private function currentUser(Request $request): ?array
    {
        $identity = $request->attributes->get('auth');
        return is_array($identity) && ($identity['session'] ?? false) === true && ($identity['cookie'] ?? false) === true
            && is_string($identity['user_id'] ?? null) && is_string($identity['token_id'] ?? null) ? $identity : null;
    }

    private function linkError(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error'=>['code'=>$code, 'message'=>$message]], $status, ['Cache-Control'=>'no-store']);
    }

}
