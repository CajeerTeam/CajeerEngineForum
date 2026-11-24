<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use CajeerEngine\Http\Router;
use CajeerEngine\Security\AuthService;
use CajeerEngine\Security\AuthenticationAssurance;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthService $auth, private Router $router, private ?AuthenticationAssurance $assurance = null) {}

    public function process(Request $request, callable $next): Response
    {
        $required = $this->router->accessPolicy($request);
        if ($required === null) {
            // Public forum pages display account-specific read state only for an active account.
            if (\CajeerEngine\Http\RouteRegistry::optionalAuthentication((string)$request->attributes->get('matched_route', ''))) {
                $identity = $this->auth->authenticateRequest($request);
                if ($identity !== null && !($identity['suspended'] ?? false)) {
                    $request->attributes->set('auth', $identity);
                    $request->attributes->set('user', $identity['user'] ?? null);
                }
            }
            return $next($request);
        }
        $identity = $this->auth->authenticateRequest($request);
        if ($identity === null) { return $this->error($request, 'unauthenticated', 401); }
        if (($identity['suspended'] ?? false) && !in_array((string)$request->attributes->get('matched_route', ''), ['api.v1.auth.me','api.v1.auth.logout','api.v1.auth.oidc.logout','api.v1.auth.sessions.index','api.v1.auth.sessions.revoke','api.v1.auth.sessions.revoke_others','api.v1.auth.sessions.revoke_all','api.v1.auth.sessions.rotate','api.v1.auth.privacy.export','api.v1.auth.privacy.clear_profile','api.v1.auth.privacy.erase','api.v1.forum.mySanctions','api.v1.forum.appealSanction'], true)) return $this->error($request, 'suspended', 403);
        if (!$this->auth->hasScopes($identity, $required)) { return $this->error($request, 'forbidden', 403); }
        if (($identity['cookie'] ?? false) && !in_array($request->getMethod(), ['GET','HEAD','OPTIONS'], true)
            && !hash_equals((string) $identity['csrf_token'], (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error($request, 'csrf_failed', 403);
        }
        if ($this->assurance !== null && AuthenticationAssurance::required($request)) {
            try { $status = $this->assurance->status($identity); }
            catch (\Throwable) { return $this->error($request, 'step_up_required', 403); }
            if (!$status['fresh']) return $this->error($request, 'step_up_required', 403);
        }
        $request->attributes->set('auth', $identity);
        $request->attributes->set('user', $identity['user'] ?? null);
        return $next($request);
    }

    private function error(Request $request, string $code, int $status): JsonResponse
    {
        \CajeerEngine\Observability\Operation::record('security','access_denied','rejected',hrtime(true));
        $response = new JsonResponse(['error' => ['code' => $code, 'message' => match ($code) {
            'unauthenticated' => 'Требуется авторизация.', 'csrf_failed' => 'Некорректный CSRF token.', 'step_up_required' => 'Повторно подтвердите вход для критической операции.', 'suspended' => 'Аккаунт заблокирован. /forum/sanctions', default => 'Недостаточно прав.',
        }, 'request_id' => (string) $request->attributes->get('request_id', '')]], $status);
        if ($status === 401) { $response->headers->set('WWW-Authenticate', 'Bearer'); }
        return $response;
    }
}
