<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

/**
 * Internal metrics collector for the runtime pack.
 *
 * Maintains counters, gauges, and a bucketized histogram for request durations.
 * All metrics are accessible via snapshot() for programmatic consumption.
 * These counters will be exploited by future specs (Observability V1, /metrics endpoint).
 *
 * Thread-safety: each worker has its own MetricsCollector instance (no shared state).
 */
final class MetricsCollector
{
    // --- Counters ---
    private int $requestsTotal = 0;

    // --- Gauges ---
    private int $workersConfigured = 0;
    private int $memoryRssBytes = 0;

    // --- Async V1 metrics ---
    private int $inflightScopes = 0;
    private int $cancelledRequestsTotal = 0;
    private int $blockingTasksTotal = 0;
    private int $blockingQueueDepth = 0;
    private int $blockingPoolRejected = 0;
    private int $blockingPoolBusyWorkers = 0;
    private int $blockingPoolSendFailed = 0;
    private int $blockingInflightCount = 0;
    private int $taskScopeChildrenMax = 0;
    private float $eventLoopLagMs = 0.0;
    private int $scopeRejectedTotal = 0;
    private int $cooperativeYieldTotal = 0;

    // --- Histogram bucketisé ---
    /** @var list<int|float> */
    private const HISTOGRAM_BUCKETS_MS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000];

    /** @var array<int, int> bucket upper bound => count */
    private array $bucketCounts = [];
    private float $durationSumMs = 0.0;
    private int $durationCount = 0;
    private float $durationMinMs = PHP_FLOAT_MAX;
    private float $durationMaxMs = 0.0;

    public function __construct()
    {
        foreach (self::HISTOGRAM_BUCKETS_MS as $bucket) {
            $this->bucketCounts[$bucket] = 0;
        }
        $this->bucketCounts[PHP_INT_MAX] = 0; // +Inf bucket
    }

    // ---- Counters ----

    /** Increments the requests_total counter. */
    public function incrementRequests(): void
    {
        $this->requestsTotal++;
    }

    // ---- Gauges ----

    /** Updates the workers_configured gauge. */
    public function setWorkersConfigured(int $count): void
    {
        $this->workersConfigured = $count;
    }

    /** Updates the memory_rss_bytes gauge. */
    public function setMemoryRss(int $bytes): void
    {
        $this->memoryRssBytes = $bytes;
    }

    /** Updates the event_loop_lag_ms gauge. Called by WorkerLifecycle::tick(). */
    public function setEventLoopLagMs(float $lagMs): void
    {
        $this->eventLoopLagMs = $lagMs;
    }

    /** Returns the current event loop lag in milliseconds. */
    public function getEventLoopLagMs(): float
    {
        return $this->eventLoopLagMs;
    }

    /** Updates the blocking_pool_busy_workers gauge. */
    public function setBlockingPoolBusyWorkers(int $count): void
    {
        $this->blockingPoolBusyWorkers = $count;
    }

    /** Updates the blocking_queue_depth gauge. */
    public function setBlockingQueueDepth(int $depth): void
    {
        $this->blockingQueueDepth = $depth;
    }

    /** Updates the blocking_inflight_count gauge (jobs sent, awaiting response). */
    public function setBlockingInflightCount(int $count): void
    {
        $this->blockingInflightCount = $count;
    }

    // ---- Async V1 counters ----

    /** Increments the inflight_scopes gauge. */
    public function incrementInflightScopes(): void
    {
        $this->inflightScopes++;
    }

    /** Decrements the inflight_scopes gauge. */
    public function decrementInflightScopes(): void
    {
        $this->inflightScopes = max(0, $this->inflightScopes - 1);
    }

    /** Increments the cancelled_requests_total counter. */
    public function incrementCancelledRequests(): void
    {
        $this->cancelledRequestsTotal++;
    }

    /** Increments the blocking_tasks_total counter. */
    public function incrementBlockingTasks(): void
    {
        $this->blockingTasksTotal++;
    }

    /** Increments the blocking_pool_rejected counter (queue full). */
    public function incrementBlockingPoolRejected(): void
    {
        $this->blockingPoolRejected++;
    }

    /** Increments the blocking_pool_send_failed counter (pool down/broken socket). */
    public function incrementBlockingPoolSendFailed(): void
    {
        $this->blockingPoolSendFailed++;
    }

    /** Increments the scope_rejected_total counter (semaphore full). */
    public function incrementScopeRejected(): void
    {
        $this->scopeRejectedTotal++;
    }

    /** Records a scope child (updates high watermark). */
    public function recordScopeChild(): void
    {
        $this->taskScopeChildrenMax++;
    }

    /** Increments the cooperative_yield_total counter. */
    public function incrementCooperativeYield(): void
    {
        $this->cooperativeYieldTotal++;
    }

    // ---- Histogram ----

    /**
     * Records a request duration in milliseconds.
     *
     * Places the value in the correct histogram bucket (value <= bucket boundary),
     * and updates sum, count, min, max.
     */
    public function recordDuration(float $durationMs): void
    {
        $this->durationSumMs += $durationMs;
        $this->durationCount++;

        if ($durationMs < $this->durationMinMs) {
            $this->durationMinMs = $durationMs;
        }
        if ($durationMs > $this->durationMaxMs) {
            $this->durationMaxMs = $durationMs;
        }

        // Find the correct bucket: value goes into the first bucket where value <= boundary
        foreach (self::HISTOGRAM_BUCKETS_MS as $bucket) {
            if ($durationMs <= $bucket) {
                $this->bucketCounts[$bucket]++;
                return;
            }
        }

        // Above all defined buckets → +Inf
        $this->bucketCounts[PHP_INT_MAX]++;
    }

    // ---- Snapshot ----

    /**
     * Returns a structured snapshot of all metrics.
     *
     * @return array{
     *     requests_total: int,
     *     request_duration_ms: array{
     *         buckets: list<array{le: int|string, count: int}>,
     *         sum: float,
     *         count: int,
     *         min: float,
     *         max: float,
     *     },
     *     workers_configured: int,
     *     memory_rss_bytes: int,
     *     inflight_scopes: int,
     *     cancelled_requests_total: int,
     *     blocking_tasks_total: int,
     *     blocking_queue_depth: int,
     *     blocking_inflight_count: int,
     *     blocking_pool_rejected: int,
     *     blocking_pool_busy_workers: int,
     *     blocking_pool_send_failed: int,
     *     taskscope_children_max: int,
     *     event_loop_lag_ms: float,
     *     scope_rejected_total: int,
     *     cooperative_yield_total: int,
     * }
     */
    public function snapshot(): array
    {
        $buckets = [];
        foreach (self::HISTOGRAM_BUCKETS_MS as $bucket) {
            $buckets[] = ['le' => $bucket, 'count' => $this->bucketCounts[$bucket]];
        }
        $buckets[] = ['le' => '+Inf', 'count' => $this->bucketCounts[PHP_INT_MAX]];

        return [
            'requests_total' => $this->requestsTotal,
            'request_duration_ms' => [
                'buckets' => $buckets,
                'sum' => $this->durationSumMs,
                'count' => $this->durationCount,
                'min' => $this->durationMinMs,
                'max' => $this->durationMaxMs,
            ],
            'workers_configured' => $this->workersConfigured,
            'memory_rss_bytes' => $this->memoryRssBytes,
            'inflight_scopes' => $this->inflightScopes,
            'cancelled_requests_total' => $this->cancelledRequestsTotal,
            'blocking_tasks_total' => $this->blockingTasksTotal,
            'blocking_queue_depth' => $this->blockingQueueDepth,
            'blocking_inflight_count' => $this->blockingInflightCount,
            'blocking_pool_rejected' => $this->blockingPoolRejected,
            'blocking_pool_busy_workers' => $this->blockingPoolBusyWorkers,
            'blocking_pool_send_failed' => $this->blockingPoolSendFailed,
            'taskscope_children_max' => $this->taskScopeChildrenMax,
            'event_loop_lag_ms' => $this->eventLoopLagMs,
            'scope_rejected_total' => $this->scopeRejectedTotal,
            'cooperative_yield_total' => $this->cooperativeYieldTotal,
        ];
    }
}
