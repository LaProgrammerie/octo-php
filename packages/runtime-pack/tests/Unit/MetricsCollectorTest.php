<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack\Tests\Unit;

use AsyncPlatform\RuntimePack\MetricsCollector;
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

        $this->assertSame(0, $snap['requests_total']);
        $this->assertSame(0, $snap['workers_configured']);
        $this->assertSame(0, $snap['memory_rss_bytes']);
        $this->assertSame(0, $snap['inflight_scopes']);
        $this->assertSame(0, $snap['cancelled_requests_total']);
        $this->assertSame(0, $snap['blocking_tasks_total']);
        $this->assertSame(0, $snap['blocking_queue_depth']);
        $this->assertSame(0, $snap['blocking_pool_rejected']);
        $this->assertSame(0, $snap['blocking_pool_busy_workers']);
        $this->assertSame(0, $snap['blocking_pool_send_failed']);
        $this->assertSame(0, $snap['taskscope_children_max']);
        $this->assertSame(0.0, $snap['event_loop_lag_ms']);
        $this->assertSame(0, $snap['scope_rejected_total']);

        // Histogram initial state
        $hist = $snap['request_duration_ms'];
        $this->assertSame(0.0, $hist['sum']);
        $this->assertSame(0, $hist['count']);
        $this->assertSame(PHP_FLOAT_MAX, $hist['min']);
        $this->assertSame(0.0, $hist['max']);

        // All bucket counts at 0
        foreach ($hist['buckets'] as $bucket) {
            $this->assertSame(0, $bucket['count']);
        }
    }

    public function testSnapshotContainsAllExpectedBuckets(): void
    {
        $snap = $this->metrics->snapshot();
        $buckets = $snap['request_duration_ms']['buckets'];

        $expectedLe = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, '+Inf'];
        $actualLe = array_map(fn(array $b) => $b['le'], $buckets);

        $this->assertSame($expectedLe, $actualLe);
    }

    // ---- incrementRequests ----

    public function testIncrementRequests(): void
    {
        $this->metrics->incrementRequests();
        $this->metrics->incrementRequests();
        $this->metrics->incrementRequests();

        $this->assertSame(3, $this->metrics->snapshot()['requests_total']);
    }

    // ---- Gauges ----

    public function testSetWorkersConfigured(): void
    {
        $this->metrics->setWorkersConfigured(8);
        $this->assertSame(8, $this->metrics->snapshot()['workers_configured']);
    }

    public function testSetMemoryRss(): void
    {
        $this->metrics->setMemoryRss(134_217_728); // 128 MB
        $this->assertSame(134_217_728, $this->metrics->snapshot()['memory_rss_bytes']);
    }

    public function testSetEventLoopLagMs(): void
    {
        $this->metrics->setEventLoopLagMs(12.5);
        $this->assertSame(12.5, $this->metrics->snapshot()['event_loop_lag_ms']);
        $this->assertSame(12.5, $this->metrics->getEventLoopLagMs());
    }

    public function testSetBlockingPoolBusyWorkers(): void
    {
        $this->metrics->setBlockingPoolBusyWorkers(3);
        $this->assertSame(3, $this->metrics->snapshot()['blocking_pool_busy_workers']);
    }

    public function testSetBlockingQueueDepth(): void
    {
        $this->metrics->setBlockingQueueDepth(42);
        $this->assertSame(42, $this->metrics->snapshot()['blocking_queue_depth']);
    }

    // ---- Async V1 counters ----

    public function testIncrementScopeRejected(): void
    {
        $this->metrics->incrementScopeRejected();
        $this->metrics->incrementScopeRejected();

        $this->assertSame(2, $this->metrics->snapshot()['scope_rejected_total']);
    }

    public function testIncrementInflightScopes(): void
    {
        $this->metrics->incrementInflightScopes();
        $this->metrics->incrementInflightScopes();
        $this->assertSame(2, $this->metrics->snapshot()['inflight_scopes']);

        $this->metrics->decrementInflightScopes();
        $this->assertSame(1, $this->metrics->snapshot()['inflight_scopes']);
    }

    public function testDecrementInflightScopesNeverNegative(): void
    {
        $this->metrics->decrementInflightScopes();
        $this->assertSame(0, $this->metrics->snapshot()['inflight_scopes']);
    }

    public function testIncrementCancelledRequests(): void
    {
        $this->metrics->incrementCancelledRequests();
        $this->assertSame(1, $this->metrics->snapshot()['cancelled_requests_total']);
    }

    public function testIncrementBlockingTasks(): void
    {
        $this->metrics->incrementBlockingTasks();
        $this->assertSame(1, $this->metrics->snapshot()['blocking_tasks_total']);
    }

    public function testIncrementBlockingPoolRejected(): void
    {
        $this->metrics->incrementBlockingPoolRejected();
        $this->assertSame(1, $this->metrics->snapshot()['blocking_pool_rejected']);
    }

    public function testIncrementBlockingPoolSendFailed(): void
    {
        $this->metrics->incrementBlockingPoolSendFailed();
        $this->assertSame(1, $this->metrics->snapshot()['blocking_pool_send_failed']);
    }

    public function testRecordScopeChild(): void
    {
        $this->metrics->recordScopeChild();
        $this->metrics->recordScopeChild();
        $this->metrics->recordScopeChild();
        $this->assertSame(3, $this->metrics->snapshot()['taskscope_children_max']);
    }

    // ---- Histogram: recordDuration ----

    public function testRecordDurationInFirstBucket(): void
    {
        $this->metrics->recordDuration(3.0); // <= 5ms → bucket 5

        $snap = $this->metrics->snapshot();
        $hist = $snap['request_duration_ms'];

        $this->assertSame(1, $hist['count']);
        $this->assertSame(3.0, $hist['sum']);
        $this->assertSame(3.0, $hist['min']);
        $this->assertSame(3.0, $hist['max']);

        // Bucket 5 should have count 1
        $this->assertSame(1, $this->findBucketCount($hist['buckets'], 5));
        // All other buckets should be 0
        $this->assertSame(0, $this->findBucketCount($hist['buckets'], 10));
    }

    public function testRecordDurationExactlyOnBucketBoundary(): void
    {
        $this->metrics->recordDuration(50.0); // exactly 50ms → bucket 50

        $hist = $this->metrics->snapshot()['request_duration_ms'];

        $this->assertSame(1, $this->findBucketCount($hist['buckets'], 50));
        $this->assertSame(0, $this->findBucketCount($hist['buckets'], 100));
        $this->assertSame(0, $this->findBucketCount($hist['buckets'], 25));
    }

    public function testRecordDurationAboveAllBucketsGoesToInf(): void
    {
        $this->metrics->recordDuration(15000.0); // > 10000ms → +Inf

        $hist = $this->metrics->snapshot()['request_duration_ms'];

        $this->assertSame(1, $this->findBucketCount($hist['buckets'], '+Inf'));
        $this->assertSame(0, $this->findBucketCount($hist['buckets'], 10000));
    }

    public function testRecordDurationUpdatesMinMax(): void
    {
        $this->metrics->recordDuration(100.0);
        $this->metrics->recordDuration(5.0);
        $this->metrics->recordDuration(500.0);

        $hist = $this->metrics->snapshot()['request_duration_ms'];

        $this->assertSame(5.0, $hist['min']);
        $this->assertSame(500.0, $hist['max']);
    }

    public function testRecordDurationUpdatesSumAndCount(): void
    {
        $this->metrics->recordDuration(10.0);
        $this->metrics->recordDuration(20.0);
        $this->metrics->recordDuration(30.0);

        $hist = $this->metrics->snapshot()['request_duration_ms'];

        $this->assertSame(3, $hist['count']);
        $this->assertSame(60.0, $hist['sum']);
    }

    public function testRecordDurationMultipleBuckets(): void
    {
        $this->metrics->recordDuration(3.0);    // bucket 5
        $this->metrics->recordDuration(7.0);    // bucket 10
        $this->metrics->recordDuration(150.0);  // bucket 250
        $this->metrics->recordDuration(9999.0); // bucket 10000
        $this->metrics->recordDuration(50000.0); // +Inf

        $hist = $this->metrics->snapshot()['request_duration_ms'];

        $this->assertSame(5, $hist['count']);
        $this->assertSame(1, $this->findBucketCount($hist['buckets'], 5));
        $this->assertSame(1, $this->findBucketCount($hist['buckets'], 10));
        $this->assertSame(1, $this->findBucketCount($hist['buckets'], 250));
        $this->assertSame(1, $this->findBucketCount($hist['buckets'], 10000));
        $this->assertSame(1, $this->findBucketCount($hist['buckets'], '+Inf'));
        // Untouched buckets
        $this->assertSame(0, $this->findBucketCount($hist['buckets'], 25));
        $this->assertSame(0, $this->findBucketCount($hist['buckets'], 50));
    }

    public function testRecordDurationZeroMs(): void
    {
        $this->metrics->recordDuration(0.0); // 0 <= 5 → bucket 5

        $hist = $this->metrics->snapshot()['request_duration_ms'];

        $this->assertSame(1, $this->findBucketCount($hist['buckets'], 5));
        $this->assertSame(0.0, $hist['min']);
        $this->assertSame(0.0, $hist['max']);
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
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $snap, "Missing key: {$key}");
        }
    }

    public function testSnapshotHistogramContainsAllExpectedKeys(): void
    {
        $hist = $this->metrics->snapshot()['request_duration_ms'];

        $this->assertArrayHasKey('buckets', $hist);
        $this->assertArrayHasKey('sum', $hist);
        $this->assertArrayHasKey('count', $hist);
        $this->assertArrayHasKey('min', $hist);
        $this->assertArrayHasKey('max', $hist);
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
        $this->fail("Bucket with le={$le} not found");
    }
}
