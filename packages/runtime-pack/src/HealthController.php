<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack;

/**
 * Health check controller for /healthz and /readyz endpoints.
 *
 * These endpoints are served directly by the worker (no scope/coroutine overhead).
 * O(1) processing, no external I/O, no external dependency checks.
 *
 * The X-Request-Id header is already set by RequestHandler before dispatch.
 * HealthController does not manage request IDs.
 */
final class HealthController
{
    private const CONTENT_TYPE = 'application/json';
    private const CACHE_CONTROL = 'no-store';
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** Stale tick threshold in seconds. */
    private const TICK_STALE_THRESHOLD = 2.0;

    public function __construct(
        private readonly WorkerLifecycle $lifecycle,
    ) {
    }

    /**
     * GET /healthz — always 200 while process is active.
     *
     * Headers: Content-Type: application/json, Cache-Control: no-store.
     * Body: {"status":"alive"}
     *
     * @param object $response OpenSwoole HTTP Response (typed as object for testability)
     */
    public function healthz(object $response): void
    {
        $response->status(200);
        $response->header('Content-Type', self::CONTENT_TYPE);
        $response->header('Cache-Control', self::CACHE_CONTROL);
        $response->end(json_encode(['status' => 'alive'], self::JSON_FLAGS));
    }

    /**
     * GET /readyz — 200 if ready, 503 if shutdown/stale/lagging.
     *
     * Check order (first match wins):
     * 1. shuttingDown → 503 {"status":"shutting_down"}
     * 2. tick stale (> 2s) → 503 {"status":"event_loop_stale"}
     * 3. lag > threshold → 503 {"status":"event_loop_lagging","lag_ms":...}
     * 4. ready → 200 {"status":"ready","event_loop_lag_ms":...}
     *
     * Headers: Content-Type: application/json, Cache-Control: no-store.
     *
     * @param object $response OpenSwoole HTTP Response (typed as object for testability)
     */
    public function readyz(object $response): void
    {
        $response->header('Content-Type', self::CONTENT_TYPE);
        $response->header('Cache-Control', self::CACHE_CONTROL);

        // 1. Shutdown check
        if ($this->lifecycle->isShuttingDown()) {
            $response->status(503);
            $response->end(json_encode(['status' => 'shutting_down'], self::JSON_FLAGS));
            return;
        }

        // 2. Tick stale check (> 2s)
        $tickAge = microtime(true) - $this->lifecycle->getLastLoopTickAt();
        if ($tickAge > self::TICK_STALE_THRESHOLD) {
            $response->status(503);
            $response->end(json_encode(['status' => 'event_loop_stale'], self::JSON_FLAGS));
            return;
        }

        // 3. Event loop lag check
        $lagMs = $this->lifecycle->getEventLoopLagMs();
        if (!$this->lifecycle->isEventLoopHealthy()) {
            $response->status(503);
            $response->end(json_encode([
                'status' => 'event_loop_lagging',
                'lag_ms' => round($lagMs, 2),
            ], self::JSON_FLAGS));
            return;
        }

        // 4. Ready
        $response->status(200);
        $response->end(json_encode([
            'status' => 'ready',
            'event_loop_lag_ms' => round($lagMs, 2),
        ], self::JSON_FLAGS));
    }
}
