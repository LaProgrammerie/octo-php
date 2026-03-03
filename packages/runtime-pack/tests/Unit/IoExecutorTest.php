<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use Octo\RuntimePack\BlockingPoolInterface;
use Octo\RuntimePack\ExecutionPolicy;
use Octo\RuntimePack\ExecutionStrategy;
use Octo\RuntimePack\IoExecutor;
use Octo\RuntimePack\JsonLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IoExecutor.
 *
 * Validates: IoExecutor routing logic — direct call vs BlockingPool offload
 * based on ExecutionPolicy strategy and directCallable availability.
 */
final class IoExecutorTest extends TestCase
{
    private ExecutionPolicy $policy;
    /** @var resource */
    private $logStream;
    private JsonLogger $logger;

    protected function setUp(): void
    {
        $this->policy = new ExecutionPolicy();
        $this->logStream = \fopen('php://memory', 'r+');
        $this->logger = new JsonLogger(false, $this->logStream);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->logStream)) {
            \fclose($this->logStream);
        }
    }

    private function getLogOutput(): string
    {
        \rewind($this->logStream);
        return \stream_get_contents($this->logStream);
    }

    private function createMockPool(mixed $returnValue = null, bool $expectCall = true): BlockingPoolInterface
    {
        $mock = $this->createMock(BlockingPoolInterface::class);
        if ($expectCall) {
            $mock->expects(self::once())
                ->method('run')
                ->willReturn($returnValue);
        } else {
            $mock->expects(self::never())
                ->method('run');
        }
        return $mock;
    }

    #[Test]
    public function directCoroutineOkWithCallableCallsDirectly(): void
    {
        $this->policy->register('redis', ExecutionStrategy::DirectCoroutineOk);
        $pool = $this->createMockPool(expectCall: false);
        $io = new IoExecutor($this->policy, $pool, $this->logger);

        $called = false;
        $result = $io->run(
            'redis',
            'cache.get',
            ['key' => 'foo'],
            function (array $payload) use (&$called) {
                $called = true;
                return 'value_' . $payload['key'];
            },
        );

        self::assertTrue($called, 'directCallable should have been called');
        self::assertSame('value_foo', $result);
        self::assertSame('', $this->getLogOutput(), 'No debug log for direct calls');
    }

    #[Test]
    public function mustOffloadAlwaysOffloadsToPool(): void
    {
        $this->policy->register('ffi', ExecutionStrategy::MustOffload);
        $pool = $this->createMock(BlockingPoolInterface::class);
        $pool->expects(self::once())
            ->method('run')
            ->with('ffi.compute', ['data' => 42], 5.0)
            ->willReturn('pool_result');

        $io = new IoExecutor($this->policy, $pool, $this->logger);

        $directCalled = false;
        $result = $io->run(
            'ffi',
            'ffi.compute',
            ['data' => 42],
            function () use (&$directCalled) {
                $directCalled = true;
                return 'direct_result';
            },
            5.0,
        );

        self::assertFalse($directCalled, 'directCallable should NOT be called for MustOffload');
        self::assertSame('pool_result', $result);
    }

    #[Test]
    public function probeRequiredOffloadsAndLogsDebug(): void
    {
        $this->policy->register('pdo_mysql', ExecutionStrategy::ProbeRequired);
        $pool = $this->createMock(BlockingPoolInterface::class);
        $pool->expects(self::once())
            ->method('run')
            ->with('db.query', ['sql' => 'SELECT 1'], null)
            ->willReturn(['row']);

        $io = new IoExecutor($this->policy, $pool, $this->logger);

        $result = $io->run(
            'pdo_mysql',
            'db.query',
            ['sql' => 'SELECT 1'],
            fn() => 'direct',
        );

        self::assertSame(['row'], $result);

        // Verify debug log was emitted
        $logOutput = $this->getLogOutput();
        self::assertStringContainsString('probe required', $logOutput);
        self::assertStringContainsString('pdo_mysql', $logOutput);
        self::assertStringContainsString('db.query', $logOutput);
    }

    #[Test]
    public function noDirectCallableAlwaysOffloadsRegardlessOfStrategy(): void
    {
        $this->policy->register('redis', ExecutionStrategy::DirectCoroutineOk);
        $pool = $this->createMock(BlockingPoolInterface::class);
        $pool->expects(self::once())
            ->method('run')
            ->with('cache.get', ['key' => 'bar'], null)
            ->willReturn('pool_value');

        $io = new IoExecutor($this->policy, $pool, $this->logger);

        // No directCallable → offload even though strategy is DirectCoroutineOk
        $result = $io->run('redis', 'cache.get', ['key' => 'bar']);

        self::assertSame('pool_value', $result);
    }

    #[Test]
    public function unknownDependencyOffloadsToPool(): void
    {
        // Don't register anything — unknown deps default to MustOffload
        $pool = $this->createMock(BlockingPoolInterface::class);
        $pool->expects(self::once())
            ->method('run')
            ->willReturn('offloaded');

        $io = new IoExecutor($this->policy, $pool, $this->logger);

        $result = $io->run(
            'unknown_lib',
            'unknown.job',
            [],
            fn() => 'should_not_run',
        );

        self::assertSame('offloaded', $result);
    }

    #[Test]
    public function mustOffloadWithoutCallableOffloads(): void
    {
        $this->policy->register('cpu_bound', ExecutionStrategy::MustOffload);
        $pool = $this->createMockPool('computed');
        $io = new IoExecutor($this->policy, $pool, $this->logger);

        $result = $io->run('cpu_bound', 'heavy.compute', ['n' => 1000]);

        self::assertSame('computed', $result);
    }

    #[Test]
    public function directCallableReceivesPayload(): void
    {
        $this->policy->register('file_io', ExecutionStrategy::DirectCoroutineOk);
        $pool = $this->createMockPool(expectCall: false);
        $io = new IoExecutor($this->policy, $pool, $this->logger);

        $receivedPayload = null;
        $io->run(
            'file_io',
            'fs.read',
            ['path' => '/tmp/test.txt'],
            function (array $payload) use (&$receivedPayload) {
                $receivedPayload = $payload;
                return 'content';
            },
        );

        self::assertSame(['path' => '/tmp/test.txt'], $receivedPayload);
    }

    #[Test]
    public function probeRequiredWithoutCallableOffloadsWithoutDebugLog(): void
    {
        $this->policy->register('doctrine_dbal', ExecutionStrategy::ProbeRequired);
        $pool = $this->createMockPool('result');
        $io = new IoExecutor($this->policy, $pool, $this->logger);

        // ProbeRequired + no callable → offload + debug log
        $result = $io->run('doctrine_dbal', 'dbal.query', ['sql' => 'SELECT 1']);

        self::assertSame('result', $result);

        // Debug log should still be emitted for ProbeRequired
        $logOutput = $this->getLogOutput();
        self::assertStringContainsString('probe required', $logOutput);
    }

    #[Test]
    public function timeoutIsPassedToBlockingPool(): void
    {
        $this->policy->register('ffi', ExecutionStrategy::MustOffload);
        $pool = $this->createMock(BlockingPoolInterface::class);
        $pool->expects(self::once())
            ->method('run')
            ->with('ffi.call', [], 10.5)
            ->willReturn(null);

        $io = new IoExecutor($this->policy, $pool, $this->logger);
        $io->run('ffi', 'ffi.call', [], null, 10.5);
    }
}
