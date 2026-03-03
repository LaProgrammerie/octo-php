<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use const PHP_FLOAT_MAX;

use Octo\RuntimePack\MetricsCollector;
use PHPUnit\Framework\TestCase;

final class MetricsCollectorTest extends TestCase
{
    private MetricsCollector $metrics;

    protected function setUp(): void
    {
        $this->metrics = new MetricsCollector();
    }

    // ---- Initial snapshot ----

    public function testInitialSnapshotAllZeroes(): void
    {
        $snap = $this->metrics->snapshot();

        self::assertSame(0, $snap['requests_total']);
        self::assertSame(0, $snap['workers_configured']);
        self::assertSame(0, $snap['memory_rss_bytes']);
        self::assertSame(0, $snap['inflight_scopes']);
        self::assertSame(0, $snap['cancelled_requests_total']);
        self::assertSame(0, $snap['blocking_tasks_total']);
        self::assertSame(0, $snap['blocking_queue_depth']);
        self::assertSame(0, $snap['blocking_pool_rejected']);
        self::assertSame(0, $snap['blocking_pool_busy_workers']);
        self::assertSame(0, $snap['blocking_pool_send_failed']);
        self::assertSame(0, $snap['taskscope_children_max']);
        self::assertSame(0.0, $snap['event_loop_lag_ms']);
        self::assertSame(0, $snap['scope_rejected_total']);
        self::assertSame(0, $snap['cooperative_yield_total']);

        // Histogram initial state
        $hist = $snap['request_duration_ms'];
        self::assertSame(0.0, $hist['sum']);
        self::assertSame(0, $hist['count']);
        self::assertSame(PHP_FLOAT_MAX, $hist['min']);
        self::assertSame(0.0, $hist['max']);

        // All bucket counts at 0
        foreach ($hist['buckets'] as $bucket) {
            self::assertSame(0, $bucket['count']);
        }
    }

    public function testSnapshotContainsAllExpectedBuckets(): void
    {
        $snap = $this->metrics->snapshot();
        $buckets = $snap['request_duration_ms']['buckets'];

        $expectedLe = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, '+Inf'];
        $actualLe = array_map(static fn (array $b) => $b['le'], $buckets);

        self::assertSame($expectedLe, $actualLe);
    }

    // ---- incrementRequests ----

    public function testIncrementRequests(): void
    {
        $this->metrics->incrementRequests();
        $this->metrics->incrementRequests();
        $this->metrics->incrementRequests();

        self::assertSame(3, $this->metrics->snapshot()['requests_total']);
    }

    // ---- Gauges ----

    public function testSetWorkersConfigured(): void
    {
        $this->metrics->setWorkersConfigured(8);
        self::assertSame(8, $this->metrics->snapshot()['workers_configured']);
    }

    public function testSetMemoryRss(): void
    {
        $this->metrics->setMemoryRss(134_217_728); // 128 MB
        self::assertSame(134_217_728, $this->metrics->snapshot()['memory_rss_bytes']);
    }

    public function testSetEventLoopLagMs(): void
    {
        $this->metrics->setEventLoopLagMs(12.5);
        self::assertSame(12.5, $this->metrics->snapshot()['event_loop_lag_ms']);
        self::assertSame(12.5, $this->metrics->getEventLoopLagMs());
    }

    public function testSetBlockingPoolBusyWorkers(): void
    {
        $this->metrics->setBlockingPoolBusyWorkers(3);
        self::assertSame(3, $this->metrics->snapshot()['blocking_pool_busy_workers']);
    }

    public function testSetBlockingQueueDepth(): void
    {
        $this->metrics->setBlockingQueueDepth(42);
        self::assertSame(42, $this->metrics->snapshot()['blocking_queue_depth']);
    }

    // ---- Async V1 counters ----

    public function testIncrementScopeRejected(): void
    {
        $this->metrics->incrementScopeRejected();
        $this->metrics->incrementScopeRejected();

        self::assertSame(2, $this->metrics->snapshot()['scope_rejected_total']);
    }

    public function testIncrementInflightScopes(): void
    {
        $this->metrics->incrementInflightScopes();
        $this->metrics->incrementInflightScopes();
        self::assertSame(2, $this->metrics->snapshot()['inflight_scopes']);

        $this->metrics->decrementInflightScopes();
        self::assertSame(1, $this->metrics->snapshot()['inflight_scopes']);
    }

    public function testDecrementInflightScopesNeverNegative(): void
    {
        $this->metrics->decrementInflightScopes();
        self::assertSame(0, $this->metrics->snapshot()['inflight_scopes']);
    }

    public function testIncrementCancelledRequests(): void
    {
        $this->metrics->incrementCancelledRequests();
        self::assertSame(1, $this->metrics->snapshot()['cancelled_requests_total']);
    }

    public function testIncrementBlockingTasks(): void
    {
        $this->metrics->incrementBlockingTasks();
        self::assertSame(1, $this->metrics->snapshot()['blocking_tasks_total']);
    }

    public function testIncrementBlockingPoolRejected(): void
    {
        $this->metrics->incrementBlockingPoolRejected();
        self::assertSame(1, $this->metrics->snapshot()['blocking_pool_rejected']);
    }

    public function testIncrementBlockingPoolSendFailed(): void
    {
        $this->metrics->incrementBlockingPoolSendFailed();
        self::assertSame(1, $this->metrics->snapshot()['blocking_pool_send_failed']);
    }

    public function testRecordScopeChild(): void
    {
        $this->metrics->recordScopeChild();
        $this->metrics->recordScopeChild();
        $this->metrics->recordScopeChild();
        self::assertSame(3, $this->metrics->snapshot()['taskscope_children_max']);
    }

    public function testIncrementCooperativeYield(): void
    {
        $this->metrics->incrementCooperativeYield();
        $this->metrics->incrementCooperativeYield();
        self::assertSame(2, $this->metrics->snapshot()['cooperative_yield_total']);
    }

    // ---- Histogram: recordDuration ----

    public function testRecordDurationInFirstBucket(): void
    {
        $this->metrics->recordDuration(3.0); // <= 5ms → bucket 5

        $snap = $this->metrics->snapshot();
        $hist = $snap['request_duration_ms'];

        self::assertSame(1, $hist['count']);
        self::assertSame(3.0, $hist['sum']);
        self::assertSame(3.0, $hist['min']);
        self::assertSame(3.0, $hist['max']);

        // Bucket 5 should have count 1
        self::assertSame(1, $this->findBucketCount($hist['buckets'], 5));
        // All other buckets should be 0
        self::assertSame(0, $this->findBucketCount($hist['buckets'], 10));
    }

    public function testRecordDurationExactlyOnBucketBoundary(): void
    {
        $this->metrics->recordDuration(50.0); // exactly 50ms → bucket 50

        $hist = $this->metrics->snapshot()['request_duration_ms'];

        self::assertSame(1, $this->findBucketCount($hist['buckets'], 50));
        self::assertSame(0, $this->findBucketCount($hist['buckets'], 100));
        self::assertSame(0, $this->findBucketCount($hist['buckets'], 25));
    }

    public function testRecordDurationAboveAllBucketsGoesToInf(): void
    {
        $this->metrics->recordDuration(15000.0); // > 10000ms → +Inf

        $hist = $this->metrics->snapshot()['request_duration_ms'];

        self::assertSame(1, $this->findBucketCount($hist['buckets'], '+Inf'));
        self::assertSame(0, $this->findBucketCount($hist['buckets'], 10000));
    }

    public function testRecordDurationUpdatesMinMax(): void
    {
        $this->metrics->recordDuration(100.0);
        $this->metrics->recordDuration(5.0);
        $this->metrics->recordDuration(500.0);

        $hist = $this->metrics->snapshot()['request_duration_ms'];

        self::assertSame(5.0, $hist['min']);
        self::assertSame(500.0, $hist['max']);
    }

    public function testRecordDurationUpdatesSumAndCount(): void
    {
        $this->metrics->recordDuration(10.0);
        $this->metrics->recordDuration(20.0);
        $this->metrics->recordDuration(30.0);

        $hist = $this->metrics->snapshot()['request_duration_ms'];

        self::assertSame(3, $hist['count']);
        self::assertSame(60.0, $hist['sum']);
    }

    public function testRecordDurationMultipleBuckets(): void
    {
        $this->metrics->recordDuration(3.0);    // bucket 5
        $this->metrics->recordDuration(7.0);    // bucket 10
        $this->metrics->recordDuration(150.0);  // bucket 250
        $this->metrics->recordDuration(9999.0); // bucket 10000
        $this->metrics->recordDuration(50000.0); // +Inf

        $hist = $this->metrics->snapshot()['request_duration_ms'];

        self::assertSame(5, $hist['count']);
        self::assertSame(1, $this->findBucketCount($hist['buckets'], 5));
        self::assertSame(1, $this->findBucketCount($hist['buckets'], 10));
        self::assertSame(1, $this->findBucketCount($hist['buckets'], 250));
        self::assertSame(1, $this->findBucketCount($hist['buckets'], 10000));
        self::assertSame(1, $this->findBucketCount($hist['buckets'], '+Inf'));
        // Untouched buckets
        self::assertSame(0, $this->findBucketCount($hist['buckets'], 25));
        self::assertSame(0, $this->findBucketCount($hist['buckets'], 50));
    }

    public function testRecordDurationZeroMs(): void
    {
        $this->metrics->recordDuration(0.0); // 0 <= 5 → bucket 5

        $hist = $this->metrics->snapshot()['request_duration_ms'];

        self::assertSame(1, $this->findBucketCount($hist['buckets'], 5));
        self::assertSame(0.0, $hist['min']);
        self::assertSame(0.0, $hist['max']);
    }

    // ---- Snapshot structure completeness ----

    public function testSnapshotContainsAllExpectedKeys(): void
    {
        $snap = $this->metrics->snapshot();

        $expectedKeys = [
            'requests_total',
            'request_duration_ms',
            'workers_configured',
            'memory_rss_bytes',
            'inflight_scopes',
            'cancelled_requests_total',
            'blocking_tasks_total',
            'blocking_queue_depth',
            'blocking_pool_rejected',
            'blocking_pool_busy_workers',
            'blocking_pool_send_failed',
            'taskscope_children_max',
            'event_loop_lag_ms',
            'scope_rejected_total',
            'cooperative_yield_total',
        ];

        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $snap, "Missing key: {$key}");
        }
    }

    public function testSnapshotHistogramContainsAllExpectedKeys(): void
    {
        $hist = $this->metrics->snapshot()['request_duration_ms'];

        self::assertArrayHasKey('buckets', $hist);
        self::assertArrayHasKey('sum', $hist);
        self::assertArrayHasKey('count', $hist);
        self::assertArrayHasKey('min', $hist);
        self::assertArrayHasKey('max', $hist);
    }

    // ---- Helper ----

    /**
     * @param list<array{le: int|string, count: int}> $buckets
     */
    private function findBucketCount(array $buckets, int|string $le): int
    {
        foreach ($buckets as $bucket) {
            if ($bucket['le'] === $le) {
                return $bucket['count'];
            }
        }
        self::fail("Bucket with le={$le} not found");
    }
}
