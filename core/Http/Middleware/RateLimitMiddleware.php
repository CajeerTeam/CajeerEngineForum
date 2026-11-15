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
        $path = rawurldecode($request->getPathInfo());
        $bucket = ((str_starts_with($path, '/api/v1/auth/') && $path !== '/api/v1/auth/privacy/export') || $path === '/api/v1/forum/register') ? 'auth' : 'global';
        $limit = $this->config->int('security.rate_limits.' . $bucket, $bucket === 'auth' ? 20 : 120);
        $window = $this->config->int('security.rate_limits.window_seconds', 60);
        $identity = ($request->getClientIp() ?: 'unknown');
        $hit = $this->limiter->hit($bucket, $identity, $limit, $window);
        // Search and author discovery consume both the existing global budget and a smaller shared budget.
        if ($hit['allowed'] && ($path === '/api/v1/forum/search' || ($path === '/api/v1/forum/members' && ($request->query->all()['discovery']??null)==='1'))) {
            $search = $this->limiter->hit('forum_search', $identity, $this->config->int('security.rate_limits.forum_search',60), $window);
            if (!$search['allowed'] || $search['remaining'] < $hit['remaining']) $hit=$search;
        }

        if (!$hit['allowed']) {
            $response = new JsonResponse([
                'error' => [
                    'code' => 'rate_limit_exceeded',
                    'message' => 'Превышен лимит запросов.',
                    'retry_after' => $hit['retry_after'],
                    'request_id' => (string) $request->attributes->get('request_id', ''),
                ],
            ], 429);
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
