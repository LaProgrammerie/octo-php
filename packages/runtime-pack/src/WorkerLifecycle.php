<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

/**
 * Per-worker lifecycle manager.
 *
 * Responsibilities:
 * - Tick timer (250ms) updating lastLoopTickAt and measuring event loop lag
 * - Inflight request counting (beginRequest/endRequest)
 * - Shutdown state management
 * - Reload policy delegation (afterRequest)
 * - Event loop health assessment (stale tick + lag threshold)
 *
 * The tick timer is registered externally (ServerBootstrap) via OpenSwoole Timer::tick().
 * This class only provides the tick() callback logic.
 */
final class WorkerLifecycle
{
    private float $lastLoopTickAt;
    private float $workerStartedAt;
    private int $requestCount = 0;
    private bool $shuttingDown = false;
    private bool $shouldExitAfterCurrentRequest = false;
    private int $inflightScopes = 0;
    private int $workerId;

    // Event-loop lag monitor (V1)
    private ?float $lastExpectedTickAt = null;
    private float $eventLoopLagMs = 0.0;
    private float $eventLoopLagThresholdMs;
    public const TICK_INTERVAL_MS = 250;

    // RSS check throttling
    private int $requestsSinceLastRssCheck = 0;
    private float $lastRssCheckAt = 0.0;
    private const RSS_CHECK_EVERY_N_REQUESTS = 100;
    private const RSS_CHECK_INTERVAL_SECONDS = 5.0;

    public function __construct(
        private readonly ServerConfig $config,
        private readonly ReloadPolicy $reloadPolicy,
        private readonly MetricsCollector $metrics,
        private readonly JsonLogger $logger,
        int $workerId = 0,
    ) {
        $now = microtime(true);
        $this->workerId = $workerId;
        $this->eventLoopLagThresholdMs = (float) $config->eventLoopLagThresholdMs;
        $this->lastLoopTickAt = $now;
        $this->workerStartedAt = $now;
    }

    /**
     * Called by the tick timer (250ms). Updates lastLoopTickAt.
     * Actively measures event loop lag: drift between expected tick time and actual tick time.
     *
     * First tick has no lag (no previous expected time to compare against).
     *
     * CPU-bound heuristic: if lag > 2× threshold and inflightScopes > 0,
     * log warning "Probable CPU-bound in handler".
     */
    public function tick(): void
    {
        $now = microtime(true);
        $this->lastLoopTickAt = $now;

        // Measure active lag: drift between expected and actual tick time
        if ($this->lastExpectedTickAt !== null) {
            $expectedNow = $this->lastExpectedTickAt + (self::TICK_INTERVAL_MS / 1000);
            $this->eventLoopLagMs = max(0.0, ($now - $expectedNow) * 1000);
            $this->metrics->setEventLoopLagMs($this->eventLoopLagMs);

            // CPU-bound heuristic: lag > 2× threshold with active requests
            if (
                $this->eventLoopLagThresholdMs > 0
                && $this->eventLoopLagMs > ($this->eventLoopLagThresholdMs * 2)
                && $this->inflightScopes > 0
            ) {
                $this->logger->warning('Probable CPU-bound in handler (event loop lag excessive)', [
                    'event_loop_lag_ms' => round($this->eventLoopLagMs, 2),
                    'threshold_ms' => $this->eventLoopLagThresholdMs,
                    'inflight_scopes' => $this->inflightScopes,
                    'worker_id' => $this->workerId,
                ]);
            }
        }

        $this->lastExpectedTickAt = $now;
    }

    /**
     * Called at the start of an accepted request. Increments inflightScopes.
     */
    public function beginRequest(): void
    {
        $this->inflightScopes++;
    }

    /**
     * Called in the finally block of handle(). Decrements inflightScopes.
     * Guard: if inflightScopes would go negative, reset to 0 + log warning.
     */
    public function endRequest(): void
    {
        $this->inflightScopes--;
        if ($this->inflightScopes < 0) {
            $this->inflightScopes = 0;
            $this->logger->warning('inflightScopes went negative, reset to 0 (possible double endRequest)');
        }
    }

    /**
     * Called after each request (after response->end()).
     * Increments request count and checks reload policy.
     *
     * - If shutting down, skip reload check.
     * - If worker started < workerRestartMinInterval ago, skip reload + log warning.
     * - RSS check is throttled: every 100 requests OR every 5 seconds.
     *
     * @return ReloadReason|null Reason for reload, or null if no reload needed
     */
    public function afterRequest(): ?ReloadReason
    {
        $this->requestCount++;

        if ($this->shuttingDown) {
            return null;
        }

        $uptimeSeconds = microtime(true) - $this->workerStartedAt;

        // Anti crash-loop guard
        if ($uptimeSeconds < $this->config->workerRestartMinInterval) {
            return null;
        }

        // RSS check throttling
        $this->requestsSinceLastRssCheck++;
        $now = microtime(true);
        $memoryRssBytes = null;

        if (
            $this->requestsSinceLastRssCheck >= self::RSS_CHECK_EVERY_N_REQUESTS
            || ($now - $this->lastRssCheckAt) >= self::RSS_CHECK_INTERVAL_SECONDS
        ) {
            $memoryRssBytes = $this->reloadPolicy->readMemoryRss();
            $this->requestsSinceLastRssCheck = 0;
            $this->lastRssCheckAt = $now;
        }

        $reason = $this->reloadPolicy->shouldReload($this->requestCount, $uptimeSeconds, $memoryRssBytes);

        if ($reason !== null) {
            $this->shouldExitAfterCurrentRequest = true;
            $this->logger->info('Worker reload triggered', [
                'worker_id' => $this->workerId,
                'reload_reason' => $reason->value,
                'request_count' => $this->requestCount,
                'uptime_seconds' => round($uptimeSeconds, 2),
                'memory_rss_bytes' => $memoryRssBytes,
            ]);
        }

        return $reason;
    }

    /**
     * Is the event loop operational?
     * Returns false if tick is stale (> 2s) OR if lag exceeds configurable threshold.
     */
    public function isEventLoopHealthy(): bool
    {
        // Passive check: tick stale (> 2s without tick)
        if (microtime(true) - $this->lastLoopTickAt > 2.0) {
            return false;
        }

        // Active check: event loop lag above configurable threshold
        if ($this->eventLoopLagThresholdMs > 0 && $this->eventLoopLagMs > $this->eventLoopLagThresholdMs) {
            return false;
        }

        return true;
    }

    /** Returns the current event loop lag in milliseconds. */
    public function getEventLoopLagMs(): float
    {
        return $this->eventLoopLagMs;
    }

    /** Is the worker shutting down? */
    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    /** Marks the worker as shutting down. */
    public function startShutdown(): void
    {
        $this->shuttingDown = true;
    }

    /** Should the worker exit(0) after the current request? */
    public function shouldExit(): bool
    {
        return $this->shouldExitAfterCurrentRequest;
    }

    /** Number of active scopes in this worker. */
    public function getInflightScopes(): int
    {
        return $this->inflightScopes;
    }

    /** Returns the worker_id. */
    public function getWorkerId(): int
    {
        return $this->workerId;
    }

    /** Returns the timestamp of the last loop tick. */
    public function getLastLoopTickAt(): float
    {
        return $this->lastLoopTickAt;
    }

    /** Returns the worker start timestamp. */
    public function getWorkerStartedAt(): float
    {
        return $this->workerStartedAt;
    }

    /** Returns the total request count for this worker. */
    public function getRequestCount(): int
    {
        return $this->requestCount;
    }
}
