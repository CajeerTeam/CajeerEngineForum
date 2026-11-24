<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\RateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private ConfigRepository $config, private RateLimiter $limiter)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $started = hrtime(true);
        $path = rawurldecode($request->getPathInfo());
        $bucket = in_array($path,['/api/v1/public/analytics/events','/api/v1/forum/analytics/page-view'],true) ? 'analytics' : ((str_starts_with($path, '/api/v1/auth/') || $path === '/api/v1/forum/register' || str_starts_with($path, '/api/v1/identity/') || str_starts_with($path, '/auth/oidc/')) ? 'auth' : 'global');
        $limit = $this->config->int('security.rate_limits.' . $bucket, $bucket === 'auth' ? 20 : ($bucket === 'analytics' ? 60 : 120));
        $window = $this->config->int('security.rate_limits.window_seconds', 60);
        $identity = \CajeerEngine\Security\AbuseIdentity::network($request->getClientIp());
        $hit = $this->limiter->hit($bucket, $identity, $limit, $window);

        if ($hit['allowed'] && (str_starts_with($path, '/api/v1/forum/ai/') || str_starts_with($path, '/api/v1/forum/support/') || str_starts_with($path, '/api/v1/forum/acp/support/'))) {
            $ai = $this->limiter->hit('forum_ai', $identity, $this->config->int('security.rate_limits.forum_ai', 30), $window);
            if (!$ai['allowed'] || $ai['remaining'] < $hit['remaining']) $hit = $ai;
        }
        if ($hit['allowed'] && ($path === '/api/v1/forum/search' || ($path === '/api/v1/forum/members' && ($request->query->all()['discovery'] ?? null) === '1'))) {
            $search = $this->limiter->hit('forum_search', $identity, $this->config->int('security.rate_limits.forum_search', 60), $window);
            if (!$search['allowed'] || $search['remaining'] < $hit['remaining']) $hit = $search;
        }
        if ($hit['allowed'] && $path === '/api/v1/platform/config/probe') {
            $probe = $this->limiter->hit('platform_config_probe', $identity, 6, 60);
            if (!$probe['allowed'] || $probe['remaining'] < $hit['remaining']) $hit = $probe;
        }
        \CajeerEngine\Observability\Operation::record('security','http_admission',$hit['allowed']?'success':(!empty($hit['unavailable'])?'failure':'rate_limited'),$started,$this->config->rootPath());
        if (!$hit['allowed']) {
            $unavailable = !empty($hit['unavailable']);
            $response = new JsonResponse([
                'error' => [
                    'code' => $unavailable ? 'rate_limit_unavailable' : 'rate_limit_exceeded',
                    'message' => $unavailable ? 'Сервис временно недоступен.' : 'Превышен лимит запросов.',
                    'retry_after' => $hit['retry_after'],
                    'request_id' => (string) $request->attributes->get('request_id', ''),
                ],
            ], $unavailable ? 503 : 429, ['Cache-Control'=>'no-store']);
            $this->applyHeaders($response, $hit);
            return $response;
        }

        $response = $next($request);
        $this->applyHeaders($response, $hit);
        return $response;
    }

    /** @param array{limit:int,remaining:int,reset_at:int,retry_after:int,key:string} $hit */
    private function applyHeaders(Response $response, array $hit): void
    {
        $response->headers->set('X-RateLimit-Limit', (string) $hit['limit']);
        $response->headers->set('X-RateLimit-Remaining', (string) $hit['remaining']);
        $response->headers->set('X-RateLimit-Reset', (string) $hit['reset_at']);
        if ($hit['retry_after'] > 0) {
            $response->headers->set('Retry-After', (string) $hit['retry_after']);
        }
    }
}
