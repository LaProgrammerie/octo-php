<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use InvalidArgumentException;
use Octo\RuntimePack\BlockingPool;
use Octo\RuntimePack\Exception\BlockingPoolSendException;
use Octo\RuntimePack\JobRegistry;
use Octo\RuntimePack\JsonLogger;
use Octo\RuntimePack\MetricsCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function is_resource;

/**
 * Unit tests for BlockingPool — pure logic parts.
 *
 * Tests that don't require OpenSwoole:
 * - routeResponse with late responses
 * - queueDepth/inflightCount/busyWorkers initial state
 * - run() validation (unknown job, not initialized)
 * - Exception types and messages
 *
 * runOrRespondError is tested via BlockingPoolRunOrRespondErrorTest
 * which uses a testable wrapper approach.
 *
 * Tests requiring OpenSwoole (Channel, Coroutine) are in integration tests.
 */
final class BlockingPoolTest extends TestCase
{
    /** @var resource */
    private $logStream;
    private JsonLogger $logger;
    private MetricsCollector $metrics;
    private JobRegistry $registry;

    protected function setUp(): void
    {
        $this->logStream = fopen('php://memory', 'r+');
        $this->logger = new JsonLogger(false, $this->logStream);
        $this->metrics = new MetricsCollector();
        $this->registry = new JobRegistry();
        $this->registry->register('test.job', static fn (array $p) => $p);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->logStream)) {
            fclose($this->logStream);
        }
    }

    // =========================================================================
    // routeResponse — late response handling
    // =========================================================================

    #[Test]
    public function routeResponseLogsWarningForUnknownJobId(): void
    {
        $pool = $this->createPool();

        $pool->routeResponse([
            'job_id' => 'expired_job_123',
            'ok' => true,
            'result' => 'too late',
        ]);

        $log = $this->getLogOutput();
        self::assertStringContainsString('late response for expired job', $log);
        self::assertStringContainsString('expired_job_123', $log);
    }

    #[Test]
    public function routeResponseLogsWarningForNullJobId(): void
    {
        $pool = $this->createPool();

        $pool->routeResponse([
            'ok' => true,
            'result' => 'no id',
        ]);

        $log = $this->getLogOutput();
        self::assertStringContainsString('late response for expired job', $log);
    }

    #[Test]
    public function routeResponseDecrementsBusyWorkers(): void
    {
        $pool = $this->createPool();

        self::assertSame(0, $pool->busyWorkers());

        // Route a response (even for unknown job) decrements — clamped to 0
        $pool->routeResponse(['job_id' => 'unknown', 'ok' => true, 'result' => null]);
        self::assertSame(0, $pool->busyWorkers());
    }

    // =========================================================================
    // Initial state tests
    // =========================================================================

    #[Test]
    public function initialQueueDepthIsZero(): void
    {
        $pool = $this->createPool();
        self::assertSame(0, $pool->queueDepth());
    }

    #[Test]
    public function initialInflightCountIsZero(): void
    {
        $pool = $this->createPool();
        self::assertSame(0, $pool->inflightCount());
    }

    #[Test]
    public function initialBusyWorkersIsZero(): void
    {
        $pool = $this->createPool();
        self::assertSame(0, $pool->busyWorkers());
    }

    // =========================================================================
    // run() — validation tests (no OpenSwoole needed for these)
    // =========================================================================

    #[Test]
    public function runThrowsForUnknownJob(): void
    {
        $pool = $this->createPool();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown job: 'nonexistent'");

        $pool->run('nonexistent', []);
    }

    #[Test]
    public function runThrowsWhenNotInitialized(): void
    {
        $pool = $this->createPool();

        // run() without initWorker() → outboundQueue is null → BlockingPoolSendException
        $this->expectException(BlockingPoolSendException::class);
        $this->expectExceptionMessage('not initialized');

        $pool->run('test.job', []);
    }

    // =========================================================================
    // getRegistry
    // =========================================================================

    #[Test]
    public function getRegistryReturnsInjectedRegistry(): void
    {
        $pool = $this->createPool();
        self::assertSame($this->registry, $pool->getRegistry());
    }

    // =========================================================================
    // stop()
    // =========================================================================

    #[Test]
    public function stopClearsPendingJobs(): void
    {
        $pool = $this->createPool();
        $pool->stop();
        self::assertSame(0, $pool->inflightCount());
    }

    // =========================================================================
    // Metrics update
    // =========================================================================

    #[Test]
    public function updateMetricsSetsCollectorValues(): void
    {
        $pool = $this->createPool();
        $pool->updateMetrics();

        $snapshot = $this->metrics->snapshot();
        self::assertSame(0, $snapshot['blocking_pool_busy_workers']);
        self::assertSame(0, $snapshot['blocking_queue_depth']);
    }

    private function getLogOutput(): string
    {
        rewind($this->logStream);

        return stream_get_contents($this->logStream);
    }

    private function createPool(): BlockingPool
    {
        return new BlockingPool(
            registry: $this->registry,
            maxWorkers: 4,
            maxQueueSize: 64,
            defaultTimeoutSeconds: 30.0,
            metrics: $this->metrics,
            logger: $this->logger,
        );
    }
}
