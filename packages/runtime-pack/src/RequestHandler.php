<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack;

/**
 * Point d'entrée pour chaque requête HTTP.
 *
 * Routing interne :
 * - /healthz → HealthController::healthz() (pas de scope, O(1))
 * - /readyz  → HealthController::readyz()  (pas de scope, O(1))
 * - autres   → app handler avec error handling
 *
 * SÉQUENCE GARANTIE :
 * 1. Extract/generate request ID (toujours, même en shutdown — Property 11)
 * 2. Set X-Request-Id header sur la réponse
 * 3. Strip version headers (Server, X-Powered-By)
 * 4. Si shuttingDown et path ∉ {/healthz, /readyz} → 503 + access log (pas de beginRequest)
 * 5. /healthz, /readyz → réponse directe (pas de scope)
 * 6. App route → beginRequest(), try { appHandler() } finally { endRequest() }
 * 7. Post-response: access log, metrics, reload check, shouldExit
 *
 * NOTE: ScopeRunner/ResponseFacade/ResponseState/TaskScope/RequestContext n'existent pas encore
 * (Tasks 16-19). Cette version simplifée délègue directement au app handler avec basic error
 * handling. Le statusCode est tracké localement en attendant ResponseState.
 */
final class RequestHandler
{
    private const JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly HealthController $health,
        private readonly RequestIdMiddleware $requestId,
        private readonly JsonLogger $logger,
        private readonly MetricsCollector $metrics,
        private readonly ServerConfig $config,
        private readonly WorkerLifecycle $lifecycle,
        /** @var callable(object, object): void */
        private readonly mixed $appHandler,
    ) {
    }

    /**
     * Handle an incoming HTTP request.
     *
     * @param object $request  OpenSwoole\Http\Request (typed as object for testability)
     * @param object $response OpenSwoole\Http\Response (typed as object for testability)
     */
    public function handle(object $request, object $response): void
    {
        $startTime = \microtime(true);

        // 1. Always resolve request ID (even during shutdown — Property 11)
        $rid = $this->requestId->resolve($request);

        // 2. Set X-Request-Id on response
        $response->header('X-Request-Id', $rid);

        // 3. Strip version headers (belt-and-suspenders with http_server_software setting)
        $response->header('Server', '');
        $response->header('X-Powered-By', '');

        $path = $request->server['request_uri'] ?? '/';
        $method = $request->server['request_method'] ?? 'GET';
        $httpLogger = $this->logger->withComponent('http')->withRequestId($rid);
        $statusCode = 200;
        $isAppRoute = false;

        try {
            // 4. Shutdown refusal: non-health routes → 503 (BEFORE beginRequest — no metric counting)
            if ($this->lifecycle->isShuttingDown() && !\in_array($path, ['/healthz', '/readyz'], true)) {
                $statusCode = 503;
                $response->status(503);
                $response->header('Content-Type', 'application/json');
                $response->end(\json_encode(['error' => 'Server shutting down'], self::JSON_FLAGS));

                return;
            }

            // 5. Healthchecks — pas de scope, O(1)
            if ($path === '/healthz') {
                $this->health->healthz($response);
                $statusCode = 200;

                return;
            }

            if ($path === '/readyz') {
                $this->health->readyz($response);
                // Approximate status for access log — HealthController sets the real status
                $statusCode = ($this->lifecycle->isShuttingDown() || !$this->lifecycle->isEventLoopHealthy())
                    ? 503
                    : 200;

                return;
            }

            // 6. App route
            $isAppRoute = true;
            $this->lifecycle->beginRequest();

            // TODO: Full ScopeRunner integration (Task 18)
            // When ScopeRunner exists:
            // - Create RequestContext with deadline
            // - Create TaskScope + ResponseFacade
            // - ScopeRunner::runRequest() synchrone yield-ok
            // - Timer::after() for deadline (408 on timeout)
            // - Semaphore check (503 if full + Retry-After:1 + scope_rejected metric)
            // - ResponseState tracks statusCode for access log

            try {
                ($this->appHandler)($request, $response);
            } catch (\Throwable $e) {
                // 10.8: Exception handling — log error (no details in prod), respond 500
                $statusCode = 500;
                $httpLogger->error('Unhandled exception in request handler', [
                    'error' => $this->config->production
                        ? 'Internal Server Error'
                        : $e->getMessage(),
                    'path' => $path,
                ]);

                // Try to send 500 if response not yet sent
                try {
                    $response->status(500);
                    $response->header('Content-Type', 'application/json');
                    $response->end(\json_encode(
                        ['error' => 'Internal Server Error'],
                        self::JSON_FLAGS,
                    ));
                } catch (\Throwable) {
                    // Response already sent — nothing to do
                }
            }
        } finally {
            // Post-response sequence (guaranteed after response sent)

            // endRequest only if we called beginRequest
            if ($isAppRoute) {
                $this->lifecycle->endRequest();
            }

            // 10.5: Access log NDJSON
            // request_id is already set via withRequestId() on $httpLogger (top-level field)
            $durationMs = (\microtime(true) - $startTime) * 1000;
            $httpLogger->info('', [
                'method' => $method,
                'path' => $path,
                'status_code' => $statusCode,
                'duration_ms' => \round($durationMs, 2),
            ]);

            // 10.6: Metrics (for all requests including healthchecks)
            $this->metrics->incrementRequests();
            $this->metrics->recordDuration($durationMs);

            // 10.7: Reload policy check + shouldExit (only for app routes)
            if ($isAppRoute) {
                $this->lifecycle->afterRequest();
                if ($this->lifecycle->shouldExit()) {
                    exit(0);
                }
            }
        }
    }
}
