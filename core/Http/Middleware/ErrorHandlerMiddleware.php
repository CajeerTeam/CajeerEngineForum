<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Runtime\RuntimeLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ConfigRepository $config,
        private RuntimeLogger $logger,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (\CajeerEngine\Search\SearchUnavailable $e) {
            $this->logger->error('Search catalog unavailable',['exception'=>$e::class]);
            return $this->jsonError($request, 'search_unavailable', 'Поиск временно недоступен.', 503);
        } catch (\CajeerEngine\Database\DatabaseUnavailable $e) {
            $this->logger->error('Database storage unavailable',['exception'=>$e::class]);
            return $this->unavailable($request);
        } catch (\CajeerEngine\Content\RevisionConflict $e) {
            return $this->jsonError($request, 'revision_conflict', $e->getMessage(), Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->jsonError($request, 'validation_failed', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            if ($e instanceof \PDOException && \CajeerEngine\Database\DatabaseUnavailable::causedBy($e)) return $this->unavailable($request);
            if ($e instanceof \PDOException) {
                // Unexpected database failures must be diagnosable without
                // exposing statements, bind values or credentials to clients.
                $this->logger->error('Unhandled HTTP exception', [
                    'request_id'=>(string)$request->attributes->get('request_id',''),
                    'exception'=>$e::class,
                    'code'=>'unexpected_database_exception',
                ]);
                return $this->jsonError($request,'internal_error','Внутренняя ошибка базы данных.',500);
            }
            // Do not echo arbitrary runtime exception messages: they can contain
            // filesystem paths, remote responses, credentials or infrastructure details.
            $missing=str_contains($e->getMessage(),'не найден');
            $this->logger->error('HTTP runtime operation failed',['request_id'=>(string)$request->attributes->get('request_id',''),'exception'=>$e::class]);
            return $this->jsonError($request,$missing?'not_found':'runtime_error',$missing?'Ресурс не найден.':'Операция отклонена.', $missing?404:400);
        } catch (\Throwable $e) {
            $requestId = (string) $request->attributes->get('request_id', '');
            $this->logger->error('Unhandled HTTP exception', [
                'request_id' => $requestId,
                'trace_id' => $request->attributes->get('trace_id'),
                'span_id' => $request->attributes->get('span_id'),
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $payload = [
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Внутренняя ошибка CajeerEngine.',
                    'request_id' => $requestId,
                ],
            ];

            if (in_array($this->config->string('app.env'), ['local','dev','testing'], true) && $this->config->bool('app.debug')) {
                $payload['error']['debug'] = [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
            }

            return new JsonResponse($payload, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function unavailable(Request $request): JsonResponse
    {
        $response=$this->jsonError($request,'database_unavailable','Хранилище временно недоступно.',503);
        $response->headers->set('Retry-After','5');
        $response->headers->set('Cache-Control','private, no-store');
        return $response;
    }

    private function jsonError(Request $request, string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => (string) $request->attributes->get('request_id', ''),
            ],
        ], $status);
    }
}
