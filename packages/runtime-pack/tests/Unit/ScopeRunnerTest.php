<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use Octo\RuntimePack\JsonLogger;
use Octo\RuntimePack\MetricsCollector;
use Octo\RuntimePack\ScopeRunner;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ScopeRunner semaphore logic.
 *
 * ScopeRunner depends on OpenSwoole primitives (Channel, Timer, Coroutine).
 * These tests require the OpenSwoole extension to be loaded.
 * Tests are skipped if the extension is not available.
 *
 * The PBT in Task 14 (Property 19) already covers the semaphore concurrency
 * invariant across many inputs. These unit tests cover specific edge cases.
 */
final class ScopeRunnerTest extends TestCase
{
    private MetricsCollector $metrics;
    private JsonLogger $logger;
    /** @var resource */
    private $logStream;

    protected function setUp(): void
    {
        if (!\extension_loaded('openswoole')) {
            $this->markTestSkipped('OpenSwoole extension required');
        }

        $this->metrics = new MetricsCollector();
        $this->logStream = \fopen('php://memory', 'r+');
        $this->logger = new JsonLogger(production: false, stream: $this->logStream);
    }

    protected function tearDown(): void
    {
        if (isset($this->logStream) && \is_resource($this->logStream)) {
            \fclose($this->logStream);
        }
    }

    private function getLogOutput(): string
    {
        \rewind($this->logStream);

        return \stream_get_contents($this->logStream) ?: '';
    }

    // --- 18.4: maxConcurrentScopes=0 → no semaphore (unlimited behavior preserved) ---

    public function testMaxConcurrentScopesZeroCreatesNoSemaphore(): void
    {
        // maxConcurrentScopes=0 → no semaphore, unlimited behavior
        $runner = new ScopeRunner($this->metrics, $this->logger, 0);

        // Use reflection to verify no semaphore was created
        $ref = new \ReflectionClass($runner);
        $prop = $ref->getProperty('concurrencySemaphore');
        $this->assertNull($prop->getValue($runner));
    }

    // --- 18.1: maxConcurrentScopes > 0 → semaphore created with N tokens ---

    public function testMaxConcurrentScopesPositiveCreatesSemaphore(): void
    {
        $channel = null;

        \OpenSwoole\Coroutine::run(function () use (&$channel) {
            $runner = new ScopeRunner($this->metrics, $this->logger, 5);

            $ref = new \ReflectionClass($runner);
            $prop = $ref->getProperty('concurrencySemaphore');
            $channel = $prop->getValue($runner);
        });

        $this->assertNotNull($channel);
        $this->assertInstanceOf(\OpenSwoole\Coroutine\Channel::class, $channel);
    }

    // --- 18.5: scope_rejected_total metric integration ---

    public function testScopeRejectedMetricInitiallyZero(): void
    {
        $snapshot = $this->metrics->snapshot();
        $this->assertSame(0, $snapshot['scope_rejected_total']);
    }

    public function testIncrementScopeRejectedUpdatesMetric(): void
    {
        $this->metrics->incrementScopeRejected();
        $this->metrics->incrementScopeRejected();
        $snapshot = $this->metrics->snapshot();
        $this->assertSame(2, $snapshot['scope_rejected_total']);
    }

    // --- 18.2: Log warning uses maxConcurrentScopes (stored property, not channel.capacity) ---

    public function testMaxConcurrentScopesStoredAsProperty(): void
    {
        $value = null;

        \OpenSwoole\Coroutine::run(function () use (&$value) {
            $runner = new ScopeRunner($this->metrics, $this->logger, 3);

            $ref = new \ReflectionClass($runner);
            $prop = $ref->getProperty('maxConcurrentScopes');
            $value = $prop->getValue($runner);
        });

        $this->assertSame(3, $value);
    }
}
