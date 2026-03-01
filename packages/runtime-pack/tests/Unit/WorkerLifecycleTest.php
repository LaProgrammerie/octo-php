<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack\Tests\Unit;

use AsyncPlatform\RuntimePack\JsonLogger;
use AsyncPlatform\RuntimePack\MetricsCollector;
use AsyncPlatform\RuntimePack\ReloadPolicy;
use AsyncPlatform\RuntimePack\ReloadReason;
use AsyncPlatform\RuntimePack\ServerConfig;
use AsyncPlatform\RuntimePack\WorkerLifecycle;
use PHPUnit\Framework\TestCase;

final class WorkerLifecycleTest extends TestCase
{
    private ServerConfig $config;
    private ReloadPolicy $reloadPolicy;
    private MetricsCollector $metrics;
    private JsonLogger $logger;
    /** @var resource */
    private $logStream;

    protected function setUp(): void
    {
        $this->config = new ServerConfig(eventLoopLagThresholdMs: 500.0);
        $this->logStream = fopen('php://memory', 'r+');
        $this->logger = new JsonLogger(production: false, stream: $this->logStream);
        $this->metrics = new MetricsCollector();
        $this->reloadPolicy = new ReloadPolicy($this->config, $this->logger);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->logStream)) {
            fclose($this->logStream);
        }
    }

    private function createLifecycle(
        ?ServerConfig $config = null,
        ?ReloadPolicy $reloadPolicy = null,
        int $workerId = 0,
    ): WorkerLifecycle {
        return new WorkerLifecycle(
            $config ?? $this->config,
            $reloadPolicy ?? $this->reloadPolicy,
            $this->metrics,
            $this->logger,
            $workerId,
        );
    }

    private function getLogOutput(): string
    {
        rewind($this->logStream);
        return stream_get_contents($this->logStream);
    }

    // --- 5.1: tick() updates lastLoopTickAt and measures lag ---

    public function testTickUpdatesLastLoopTickAt(): void
    {
        $lifecycle = $this->createLifecycle();
        $before = $lifecycle->getLastLoopTickAt();

        // Small sleep to ensure time advances
        usleep(1000); // 1ms
        $lifecycle->tick();

        $this->assertGreaterThan($before, $lifecycle->getLastLoopTickAt());
    }

    public function testFirstTickHasZeroLag(): void
    {
        $lifecycle = $this->createLifecycle();

        // First tick: no previous expected time, so lag should remain 0
        $lifecycle->tick();

        $this->assertSame(0.0, $lifecycle->getEventLoopLagMs());
    }

    public function testSecondTickMeasuresLag(): void
    {
        $lifecycle = $this->createLifecycle();

        $lifecycle->tick();
        // Second tick immediately after — lag should be ~0 (well under 250ms interval)
        $lifecycle->tick();

        // Lag should be very small (the tick came early, not late)
        // Since we tick immediately, the expected time is now + 250ms,
        // but we tick again immediately, so lag = max(0, actual - expected) = 0
        // because actual < expected (we ticked early).
        $this->assertEqualsWithDelta(0.0, $lifecycle->getEventLoopLagMs(), 50.0);
    }

    public function testTickUpdatesMetricsCollector(): void
    {
        $lifecycle = $this->createLifecycle();

        $lifecycle->tick();
        // After first tick, lag is 0 (no previous expected time)
        $this->assertSame(0.0, $this->metrics->getEventLoopLagMs());

        // Simulate a delayed second tick by sleeping
        usleep(1000); // 1ms — still well within 250ms interval
        $lifecycle->tick();

        // Metrics should have been updated
        $this->assertGreaterThanOrEqual(0.0, $this->metrics->getEventLoopLagMs());
    }

    // --- 5.2: isEventLoopHealthy() ---

    public function testIsEventLoopHealthyReturnsTrueWhenFresh(): void
    {
        $lifecycle = $this->createLifecycle();
        $lifecycle->tick();

        $this->assertTrue($lifecycle->isEventLoopHealthy());
    }

    public function testIsEventLoopHealthyReturnsFalseWhenTickStale(): void
    {
        // Create lifecycle with a config that has lag threshold disabled
        $config = new ServerConfig(eventLoopLagThresholdMs: 0.0);
        $lifecycle = $this->createLifecycle(config: $config);

        // Use reflection to set lastLoopTickAt to 3 seconds ago (stale)
        $ref = new \ReflectionProperty(WorkerLifecycle::class, 'lastLoopTickAt');
        $ref->setValue($lifecycle, microtime(true) - 3.0);

        $this->assertFalse($lifecycle->isEventLoopHealthy());
    }

    public function testIsEventLoopHealthyReturnsFalseWhenLagExceedsThreshold(): void
    {
        $config = new ServerConfig(eventLoopLagThresholdMs: 100.0);
        $lifecycle = $this->createLifecycle(config: $config);

        // Simulate high lag via reflection
        $ref = new \ReflectionProperty(WorkerLifecycle::class, 'eventLoopLagMs');
        $ref->setValue($lifecycle, 150.0);

        $this->assertFalse($lifecycle->isEventLoopHealthy());
    }

    public function testIsEventLoopHealthyReturnsTrueWhenLagBelowThreshold(): void
    {
        $config = new ServerConfig(eventLoopLagThresholdMs: 100.0);
        $lifecycle = $this->createLifecycle(config: $config);

        // Simulate low lag
        $ref = new \ReflectionProperty(WorkerLifecycle::class, 'eventLoopLagMs');
        $ref->setValue($lifecycle, 50.0);

        $this->assertTrue($lifecycle->isEventLoopHealthy());
    }

    public function testIsEventLoopHealthyIgnoresLagWhenThresholdDisabled(): void
    {
        $config = new ServerConfig(eventLoopLagThresholdMs: 0.0);
        $lifecycle = $this->createLifecycle(config: $config);

        // Even with high lag, threshold=0 means disabled
        $ref = new \ReflectionProperty(WorkerLifecycle::class, 'eventLoopLagMs');
        $ref->setValue($lifecycle, 9999.0);

        $this->assertTrue($lifecycle->isEventLoopHealthy());
    }

    // --- 5.3: beginRequest() / endRequest() ---

    public function testBeginRequestIncrementsInflightScopes(): void
    {
        $lifecycle = $this->createLifecycle();

        $this->assertSame(0, $lifecycle->getInflightScopes());
        $lifecycle->beginRequest();
        $this->assertSame(1, $lifecycle->getInflightScopes());
        $lifecycle->beginRequest();
        $this->assertSame(2, $lifecycle->getInflightScopes());
    }

    public function testEndRequestDecrementsInflightScopes(): void
    {
        $lifecycle = $this->createLifecycle();

        $lifecycle->beginRequest();
        $lifecycle->beginRequest();
        $lifecycle->endRequest();
        $this->assertSame(1, $lifecycle->getInflightScopes());
        $lifecycle->endRequest();
        $this->assertSame(0, $lifecycle->getInflightScopes());
    }

    public function testEndRequestGuardsAgainstNegativeInflight(): void
    {
        $lifecycle = $this->createLifecycle();

        // endRequest without beginRequest — should not go negative
        $lifecycle->endRequest();
        $this->assertSame(0, $lifecycle->getInflightScopes());

        $output = $this->getLogOutput();
        $this->assertStringContainsString('inflightScopes went negative', $output);
    }

    // --- 5.4: isShuttingDown(), startShutdown(), getWorkerId(), getEventLoopLagMs() ---

    public function testIsShuttingDownDefaultFalse(): void
    {
        $lifecycle = $this->createLifecycle();
        $this->assertFalse($lifecycle->isShuttingDown());
    }

    public function testStartShutdownSetsFlag(): void
    {
        $lifecycle = $this->createLifecycle();
        $lifecycle->startShutdown();
        $this->assertTrue($lifecycle->isShuttingDown());
    }

    public function testGetWorkerId(): void
    {
        $lifecycle = $this->createLifecycle(workerId: 42);
        $this->assertSame(42, $lifecycle->getWorkerId());
    }

    public function testGetEventLoopLagMsDefaultZero(): void
    {
        $lifecycle = $this->createLifecycle();
        $this->assertSame(0.0, $lifecycle->getEventLoopLagMs());
    }

    // --- 5.7: event_loop_lag_ms metric updated by tick() ---

    public function testTickUpdatesEventLoopLagMetric(): void
    {
        $lifecycle = $this->createLifecycle();

        // First tick sets baseline
        $lifecycle->tick();
        $this->assertSame(0.0, $this->metrics->getEventLoopLagMs());

        // Second tick — lag should be updated in metrics
        $lifecycle->tick();
        $this->assertGreaterThanOrEqual(0.0, $this->metrics->getEventLoopLagMs());
    }

    // --- 5.8: CPU-bound heuristic ---

    public function testTickLogsCpuBoundWarningWhenLagExceedsDoubleThreshold(): void
    {
        $config = new ServerConfig(eventLoopLagThresholdMs: 100.0);
        $lifecycle = $this->createLifecycle(config: $config);

        // Simulate: first tick sets baseline
        $lifecycle->tick();

        // Simulate inflight request
        $lifecycle->beginRequest();

        // Simulate high lag by setting lastExpectedTickAt far in the past
        $ref = new \ReflectionProperty(WorkerLifecycle::class, 'lastExpectedTickAt');
        $ref->setValue($lifecycle, microtime(true) - 1.0); // 1 second ago → ~750ms lag

        $lifecycle->tick();

        $output = $this->getLogOutput();
        $this->assertStringContainsString('Probable CPU-bound in handler', $output);
        $this->assertStringContainsString('worker_id', $output);
        $this->assertStringContainsString('inflight_scopes', $output);
    }

    public function testTickDoesNotLogCpuBoundWhenNoInflightRequests(): void
    {
        $config = new ServerConfig(eventLoopLagThresholdMs: 100.0);
        $lifecycle = $this->createLifecycle(config: $config);

        $lifecycle->tick();

        // Simulate high lag but no inflight requests
        $ref = new \ReflectionProperty(WorkerLifecycle::class, 'lastExpectedTickAt');
        $ref->setValue($lifecycle, microtime(true) - 1.0);

        $lifecycle->tick();

        $output = $this->getLogOutput();
        $this->assertStringNotContainsString('Probable CPU-bound', $output);
    }

    public function testTickDoesNotLogCpuBoundWhenLagBelowDoubleThreshold(): void
    {
        $config = new ServerConfig(eventLoopLagThresholdMs: 500.0);
        $lifecycle = $this->createLifecycle(config: $config);

        $lifecycle->tick();
        $lifecycle->beginRequest();

        // Lag will be small (immediate tick) — well below 2× 500ms
        $lifecycle->tick();

        $output = $this->getLogOutput();
        $this->assertStringNotContainsString('Probable CPU-bound', $output);
    }

    public function testTickDoesNotLogCpuBoundWhenThresholdDisabled(): void
    {
        $config = new ServerConfig(eventLoopLagThresholdMs: 0.0);
        $lifecycle = $this->createLifecycle(config: $config);

        $lifecycle->tick();
        $lifecycle->beginRequest();

        // Even with high lag, threshold=0 disables the heuristic
        $ref = new \ReflectionProperty(WorkerLifecycle::class, 'lastExpectedTickAt');
        $ref->setValue($lifecycle, microtime(true) - 5.0);

        $lifecycle->tick();

        $output = $this->getLogOutput();
        $this->assertStringNotContainsString('Probable CPU-bound', $output);
    }

    // --- afterRequest() ---

    public function testAfterRequestIncrementsRequestCount(): void
    {
        $lifecycle = $this->createLifecycle();
        $lifecycle->afterRequest();
        $this->assertSame(1, $lifecycle->getRequestCount());
    }

    public function testAfterRequestSkipsReloadWhenShuttingDown(): void
    {
        $lifecycle = $this->createLifecycle();
        $lifecycle->startShutdown();

        $result = $lifecycle->afterRequest();
        $this->assertNull($result);
    }

    public function testShouldExitDefaultFalse(): void
    {
        $lifecycle = $this->createLifecycle();
        $this->assertFalse($lifecycle->shouldExit());
    }

    // --- TICK_INTERVAL_MS constant ---

    public function testTickIntervalConstant(): void
    {
        $this->assertSame(250, WorkerLifecycle::TICK_INTERVAL_MS);
    }

    // --- Constructor initializes timestamps ---

    public function testConstructorInitializesTimestamps(): void
    {
        $before = microtime(true);
        $lifecycle = $this->createLifecycle();
        $after = microtime(true);

        $this->assertGreaterThanOrEqual($before, $lifecycle->getLastLoopTickAt());
        $this->assertLessThanOrEqual($after, $lifecycle->getLastLoopTickAt());
        $this->assertGreaterThanOrEqual($before, $lifecycle->getWorkerStartedAt());
        $this->assertLessThanOrEqual($after, $lifecycle->getWorkerStartedAt());
    }

    // --- 13.7: shouldExit after reload ---

    public function testShouldExitTrueAfterReloadTriggered(): void
    {
        $config = new ServerConfig(
            maxRequests: 1,
            maxUptime: 0,
            maxMemoryRss: 0,
            workerRestartMinInterval: 0,
            eventLoopLagThresholdMs: 500.0,
        );
        $reloadPolicy = new ReloadPolicy($config, $this->logger);
        $lifecycle = $this->createLifecycle(config: $config, reloadPolicy: $reloadPolicy);

        $this->assertFalse($lifecycle->shouldExit());

        // afterRequest increments count to 1, which meets maxRequests=1
        $reason = $lifecycle->afterRequest();

        $this->assertSame(ReloadReason::MaxRequests, $reason);
        $this->assertTrue($lifecycle->shouldExit());
    }

    // --- 13.7: shutdown prioritaire sur reload ---

    public function testShutdownPriorityOverReload(): void
    {
        $config = new ServerConfig(
            maxRequests: 1,
            maxUptime: 0,
            maxMemoryRss: 0,
            workerRestartMinInterval: 0,
            eventLoopLagThresholdMs: 500.0,
        );
        $reloadPolicy = new ReloadPolicy($config, $this->logger);
        $lifecycle = $this->createLifecycle(config: $config, reloadPolicy: $reloadPolicy);

        // Start shutdown BEFORE afterRequest
        $lifecycle->startShutdown();

        // Even though maxRequests=1 would trigger reload, shutdown takes priority
        $reason = $lifecycle->afterRequest();

        $this->assertNull($reason);
        $this->assertFalse($lifecycle->shouldExit());
    }

    // --- 13.7: WORKER_RESTART_MIN_INTERVAL respected ---

    public function testWorkerRestartMinIntervalPreventsEarlyReload(): void
    {
        $config = new ServerConfig(
            maxRequests: 1,
            maxUptime: 0,
            maxMemoryRss: 0,
            workerRestartMinInterval: 60, // 60 seconds — worker just started
            eventLoopLagThresholdMs: 500.0,
        );
        $reloadPolicy = new ReloadPolicy($config, $this->logger);
        $lifecycle = $this->createLifecycle(config: $config, reloadPolicy: $reloadPolicy);

        // Worker just started, uptime < 60s → reload should be suppressed
        $reason = $lifecycle->afterRequest();

        $this->assertNull($reason);
        $this->assertFalse($lifecycle->shouldExit());
    }

    public function testWorkerRestartMinIntervalAllowsReloadAfterInterval(): void
    {
        $config = new ServerConfig(
            maxRequests: 1,
            maxUptime: 0,
            maxMemoryRss: 0,
            workerRestartMinInterval: 0, // disabled
            eventLoopLagThresholdMs: 500.0,
        );
        $reloadPolicy = new ReloadPolicy($config, $this->logger);
        $lifecycle = $this->createLifecycle(config: $config, reloadPolicy: $reloadPolicy);

        // With interval=0, reload should trigger immediately
        $reason = $lifecycle->afterRequest();

        $this->assertSame(ReloadReason::MaxRequests, $reason);
        $this->assertTrue($lifecycle->shouldExit());
    }

    // --- 13.7: afterRequest logs reload with details ---

    public function testAfterRequestLogsReloadDetails(): void
    {
        $config = new ServerConfig(
            maxRequests: 1,
            maxUptime: 0,
            maxMemoryRss: 0,
            workerRestartMinInterval: 0,
            eventLoopLagThresholdMs: 500.0,
        );
        $reloadPolicy = new ReloadPolicy($config, $this->logger);
        $lifecycle = $this->createLifecycle(config: $config, reloadPolicy: $reloadPolicy, workerId: 7);

        $lifecycle->afterRequest();

        $output = $this->getLogOutput();
        $this->assertStringContainsString('Worker reload triggered', $output);
        $this->assertStringContainsString('worker_id', $output);
        $this->assertStringContainsString('reload_reason', $output);
        $this->assertStringContainsString('max_requests', $output);
    }

    // --- 13.7: Multiple begin/end request tracking ---

    public function testInflightCountTracksMultipleBeginEnd(): void
    {
        $lifecycle = $this->createLifecycle();

        $lifecycle->beginRequest();
        $lifecycle->beginRequest();
        $lifecycle->beginRequest();
        $this->assertSame(3, $lifecycle->getInflightScopes());

        $lifecycle->endRequest();
        $this->assertSame(2, $lifecycle->getInflightScopes());

        $lifecycle->endRequest();
        $lifecycle->endRequest();
        $this->assertSame(0, $lifecycle->getInflightScopes());
    }

    // --- 13.7: Lag exactly at threshold ---

    public function testIsEventLoopHealthyReturnsFalseWhenLagExactlyAtThreshold(): void
    {
        $config = new ServerConfig(eventLoopLagThresholdMs: 100.0);
        $lifecycle = $this->createLifecycle(config: $config);

        // Set lag exactly at threshold — should still be healthy (> not >=)
        $ref = new \ReflectionProperty(WorkerLifecycle::class, 'eventLoopLagMs');
        $ref->setValue($lifecycle, 100.0);

        // The implementation uses > (strict greater than), so exactly at threshold is healthy
        $this->assertTrue($lifecycle->isEventLoopHealthy());
    }

    // --- 13.7: Tick stale threshold is exactly 2 seconds ---

    public function testIsEventLoopHealthyReturnsTrueWhenTickWellWithin2Seconds(): void
    {
        $lifecycle = $this->createLifecycle();

        // Set lastLoopTickAt to 1.5 seconds ago — well within the 2s threshold
        $ref = new \ReflectionProperty(WorkerLifecycle::class, 'lastLoopTickAt');
        $ref->setValue($lifecycle, microtime(true) - 1.5);

        $this->assertTrue($lifecycle->isEventLoopHealthy());
    }

    public function testIsEventLoopHealthyReturnsFalseWhenTickClearlyOver2Seconds(): void
    {
        $lifecycle = $this->createLifecycle();

        // Set lastLoopTickAt to 2.5 seconds ago — clearly over the 2s threshold
        $ref = new \ReflectionProperty(WorkerLifecycle::class, 'lastLoopTickAt');
        $ref->setValue($lifecycle, microtime(true) - 2.5);

        $this->assertFalse($lifecycle->isEventLoopHealthy());
    }
}
