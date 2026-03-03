<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Unit;

require_once __DIR__ . '/TestDoubles.php';

use Doctrine\ORM\EntityManagerInterface;
use Octo\RuntimePack\MetricsCollector;
use Octo\SymfonyBridge\DoctrineResetHook;
use Octo\SymfonyBridge\MetricsBridge;
use Octo\SymfonyBridge\ResetManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResetManager::class)]
final class ResetManagerTest extends TestCase
{
    private SpyLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new SpyLogger();
    }

    #[Test]
    public function itCallsKernelResetWhenKernelImplementsResetInterface(): void
    {
        $kernel = new ResettableKernel();
        $manager = new ResetManager($kernel, $this->logger, null);

        $manager->reset('req-001');

        self::assertTrue($kernel->resetCalled);
    }

    #[Test]
    public function itCallsServicesResetterWhenKernelHasContainerWithResetter(): void
    {
        $resetter = new FakeServicesResetter();
        $container = new FakeContainer(['services_resetter' => $resetter]);
        $kernel = new KernelWithContainer($container);
        $manager = new ResetManager($kernel, $this->logger, null);

        $manager->reset('req-002');

        self::assertTrue($resetter->resetCalled);
    }

    #[Test]
    public function itLogsWarningForBestEffortStrategy(): void
    {
        $kernel = new BareKernel();
        $manager = new ResetManager($kernel, $this->logger, null);

        $manager->reset('req-003');

        self::assertTrue(
            $this->logger->hasWarningContaining('No complete reset strategy found'),
        );
    }

    #[Test]
    public function itPrefersResetInterfaceOverServicesResetter(): void
    {
        $kernel = new ResettableKernelWithContainer();
        $manager = new ResetManager($kernel, $this->logger, null);

        $manager->reset('req-004');

        self::assertTrue($kernel->resetCalled);
        self::assertFalse($kernel->container->servicesResetter->resetCalled);
    }

    #[Test]
    public function itExecutesHooksAfterMainReset(): void
    {
        $kernel = new ResettableKernel();
        $hook1 = new SpyResetHook();
        $hook2 = new SpyResetHook();
        $manager = new ResetManager($kernel, $this->logger, null);
        $manager->addHook($hook1);
        $manager->addHook($hook2);

        $manager->reset('req-005');

        self::assertTrue($kernel->resetCalled);
        self::assertTrue($hook1->called);
        self::assertTrue($hook2->called);
    }

    #[Test]
    public function itLogsErrorAndContinuesWhenHookThrows(): void
    {
        $kernel = new BareKernel();
        $failingHook = new FailingResetHook();
        $successHook = new SpyResetHook();
        $manager = new ResetManager($kernel, $this->logger, null);
        $manager->addHook($failingHook);
        $manager->addHook($successHook);

        $manager->reset('req-006');

        self::assertTrue($this->logger->hasErrorContaining('ResetHook failed'));
        self::assertTrue($successHook->called, 'Second hook should still execute after first hook fails');
    }

    #[Test]
    public function itLogsErrorAndContinuesWhenKernelResetThrows(): void
    {
        $kernel = new ThrowingResettableKernel();
        $hook = new SpyResetHook();
        $manager = new ResetManager($kernel, $this->logger, null);
        $manager->addHook($hook);

        $manager->reset('req-007');

        self::assertTrue($this->logger->hasErrorContaining('Reset failed'));
        self::assertTrue($hook->called, 'Hooks must execute even when kernel reset throws');
    }

    #[Test]
    public function itLogsDebugWithDurationAfterReset(): void
    {
        $kernel = new BareKernel();
        $manager = new ResetManager($kernel, $this->logger, null);

        $manager->reset('req-008');

        $debugLogs = array_filter(
            $this->logger->logs,
            static fn (array $log) => $log['level'] === 'debug' && str_contains($log['message'], 'Reset completed'),
        );
        self::assertNotEmpty($debugLogs);

        $log = array_values($debugLogs)[0];
        self::assertSame('req-008', $log['context']['request_id']);
        self::assertArrayHasKey('reset_duration_ms', $log['context']);
        self::assertIsFloat($log['context']['reset_duration_ms']);
    }

    #[Test]
    public function itLogsWarningWhenDurationExceedsThreshold(): void
    {
        $kernel = new SlowResettableKernel(sleepUs: 60_000);
        $manager = new ResetManager($kernel, $this->logger, null, resetWarningMs: 50);

        $manager->reset('req-009');

        $warningLogs = array_filter(
            $this->logger->logs,
            static fn (array $log) => $log['level'] === 'warning' && str_contains($log['message'], 'Reset duration exceeded threshold'),
        );
        self::assertNotEmpty($warningLogs);

        $log = array_values($warningLogs)[0];
        self::assertSame('req-009', $log['context']['request_id']);
        self::assertSame(50, $log['context']['threshold_ms']);
    }

    #[Test]
    public function itDoesNotWarnWhenDurationIsBelowThreshold(): void
    {
        $kernel = new ResettableKernel();
        $manager = new ResetManager($kernel, $this->logger, null, resetWarningMs: 50);

        $manager->reset('req-010');

        $warningLogs = array_filter(
            $this->logger->logs,
            static fn (array $log) => $log['level'] === 'warning' && str_contains($log['message'], 'Reset duration exceeded'),
        );
        self::assertEmpty($warningLogs);
    }

    #[Test]
    public function itRecordsResetDurationMetricWhenMetricsBridgeProvided(): void
    {
        $kernel = new ResettableKernel();
        $metricsBridge = new MetricsBridge(new MetricsCollector());
        $manager = new ResetManager($kernel, $this->logger, $metricsBridge);

        $manager->reset('req-011');

        $snapshot = $metricsBridge->snapshot();
        self::assertGreaterThanOrEqual(0.0, $snapshot['symfony_reset_duration_sum_ms']);
    }

    #[Test]
    public function itWorksWithoutMetricsBridge(): void
    {
        $kernel = new ResettableKernel();
        $manager = new ResetManager($kernel, $this->logger, null);

        $manager->reset('req-012');

        self::assertTrue($kernel->resetCalled);
    }

    #[Test]
    public function doctrineHookRollsBackOrphanedTransaction(): void
    {
        if (!interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('doctrine/orm not installed');
        }

        $connection = new FakeDoctrineConnection(transactionActive: true);
        $em = new FakeEntityManager($connection);

        /** @var EntityManagerInterface $em */
        $hook = new DoctrineResetHook($em, $this->logger);

        $hook->reset();

        self::assertTrue($connection->rollbackCalled);
        self::assertTrue($this->logger->hasWarningContaining('Orphaned transaction rolled back'));
        self::assertTrue($em->clearCalled);
    }

    #[Test]
    public function doctrineHookClearsWithoutRollbackWhenNoTransaction(): void
    {
        if (!interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('doctrine/orm not installed');
        }

        $connection = new FakeDoctrineConnection(transactionActive: false);
        $em = new FakeEntityManager($connection);

        /** @var EntityManagerInterface $em */
        $hook = new DoctrineResetHook($em, $this->logger);

        $hook->reset();

        self::assertFalse($connection->rollbackCalled);
        self::assertTrue($em->clearCalled);
    }

    #[Test]
    public function itExposesResetWarningThreshold(): void
    {
        $manager = new ResetManager(new BareKernel(), $this->logger, null, resetWarningMs: 75);

        self::assertSame(75, $manager->getResetWarningMs());
    }
}
