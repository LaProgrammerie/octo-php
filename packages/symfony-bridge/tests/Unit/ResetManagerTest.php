<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Unit;

require_once __DIR__ . '/TestDoubles.php';

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
    public function it_calls_kernel_reset_when_kernel_implements_reset_interface(): void
    {
        $kernel = new ResettableKernel();
        $manager = new ResetManager($kernel, $this->logger, null);

        $manager->reset('req-001');

        $this->assertTrue($kernel->resetCalled);
    }

    #[Test]
    public function it_calls_services_resetter_when_kernel_has_container_with_resetter(): void
    {
        $resetter = new FakeServicesResetter();
        $container = new FakeContainer(['services_resetter' => $resetter]);
        $kernel = new KernelWithContainer($container);
        $manager = new ResetManager($kernel, $this->logger, null);

        $manager->reset('req-002');

        $this->assertTrue($resetter->resetCalled);
    }

    #[Test]
    public function it_logs_warning_for_best_effort_strategy(): void
    {
        $kernel = new BareKernel();
        $manager = new ResetManager($kernel, $this->logger, null);

        $manager->reset('req-003');

        $this->assertTrue(
            $this->logger->hasWarningContaining('No complete reset strategy found'),
        );
    }

    #[Test]
    public function it_prefers_reset_interface_over_services_resetter(): void
    {
        $kernel = new ResettableKernelWithContainer();
        $manager = new ResetManager($kernel, $this->logger, null);

        $manager->reset('req-004');

        $this->assertTrue($kernel->resetCalled);
        $this->assertFalse($kernel->container->servicesResetter->resetCalled);
    }

    #[Test]
    public function it_executes_hooks_after_main_reset(): void
    {
        $kernel = new ResettableKernel();
        $hook1 = new SpyResetHook();
        $hook2 = new SpyResetHook();
        $manager = new ResetManager($kernel, $this->logger, null);
        $manager->addHook($hook1);
        $manager->addHook($hook2);

        $manager->reset('req-005');

        $this->assertTrue($kernel->resetCalled);
        $this->assertTrue($hook1->called);
        $this->assertTrue($hook2->called);
    }

    #[Test]
    public function it_logs_error_and_continues_when_hook_throws(): void
    {
        $kernel = new BareKernel();
        $failingHook = new FailingResetHook();
        $successHook = new SpyResetHook();
        $manager = new ResetManager($kernel, $this->logger, null);
        $manager->addHook($failingHook);
        $manager->addHook($successHook);

        $manager->reset('req-006');

        $this->assertTrue($this->logger->hasErrorContaining('ResetHook failed'));
        $this->assertTrue($successHook->called, 'Second hook should still execute after first hook fails');
    }

    #[Test]
    public function it_logs_error_and_continues_when_kernel_reset_throws(): void
    {
        $kernel = new ThrowingResettableKernel();
        $hook = new SpyResetHook();
        $manager = new ResetManager($kernel, $this->logger, null);
        $manager->addHook($hook);

        $manager->reset('req-007');

        $this->assertTrue($this->logger->hasErrorContaining('Reset failed'));
        $this->assertTrue($hook->called, 'Hooks must execute even when kernel reset throws');
    }

    #[Test]
    public function it_logs_debug_with_duration_after_reset(): void
    {
        $kernel = new BareKernel();
        $manager = new ResetManager($kernel, $this->logger, null);

        $manager->reset('req-008');

        $debugLogs = array_filter(
            $this->logger->logs,
            fn(array $log) => $log['level'] === 'debug' && str_contains($log['message'], 'Reset completed'),
        );
        $this->assertNotEmpty($debugLogs);

        $log = array_values($debugLogs)[0];
        $this->assertSame('req-008', $log['context']['request_id']);
        $this->assertArrayHasKey('reset_duration_ms', $log['context']);
        $this->assertIsFloat($log['context']['reset_duration_ms']);
    }

    #[Test]
    public function it_logs_warning_when_duration_exceeds_threshold(): void
    {
        $kernel = new SlowResettableKernel(sleepUs: 60_000);
        $manager = new ResetManager($kernel, $this->logger, null, resetWarningMs: 50);

        $manager->reset('req-009');

        $warningLogs = array_filter(
            $this->logger->logs,
            fn(array $log) => $log['level'] === 'warning' && str_contains($log['message'], 'Reset duration exceeded threshold'),
        );
        $this->assertNotEmpty($warningLogs);

        $log = array_values($warningLogs)[0];
        $this->assertSame('req-009', $log['context']['request_id']);
        $this->assertSame(50, $log['context']['threshold_ms']);
    }

    #[Test]
    public function it_does_not_warn_when_duration_is_below_threshold(): void
    {
        $kernel = new ResettableKernel();
        $manager = new ResetManager($kernel, $this->logger, null, resetWarningMs: 50);

        $manager->reset('req-010');

        $warningLogs = array_filter(
            $this->logger->logs,
            fn(array $log) => $log['level'] === 'warning' && str_contains($log['message'], 'Reset duration exceeded'),
        );
        $this->assertEmpty($warningLogs);
    }

    #[Test]
    public function it_records_reset_duration_metric_when_metrics_bridge_provided(): void
    {
        $kernel = new ResettableKernel();
        $metricsBridge = new MetricsBridge(new MetricsCollector());
        $manager = new ResetManager($kernel, $this->logger, $metricsBridge);

        $manager->reset('req-011');

        $snapshot = $metricsBridge->snapshot();
        $this->assertGreaterThanOrEqual(0.0, $snapshot['symfony_reset_duration_sum_ms']);
    }

    #[Test]
    public function it_works_without_metrics_bridge(): void
    {
        $kernel = new ResettableKernel();
        $manager = new ResetManager($kernel, $this->logger, null);

        $manager->reset('req-012');

        $this->assertTrue($kernel->resetCalled);
    }

    #[Test]
    public function doctrine_hook_rolls_back_orphaned_transaction(): void
    {
        if (!interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
            $this->markTestSkipped('doctrine/orm not installed');
        }

        $connection = new FakeDoctrineConnection(transactionActive: true);
        $em = new FakeEntityManager($connection);
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $hook = new DoctrineResetHook($em, $this->logger);

        $hook->reset();

        $this->assertTrue($connection->rollbackCalled);
        $this->assertTrue($this->logger->hasWarningContaining('Orphaned transaction rolled back'));
        $this->assertTrue($em->clearCalled);
    }

    #[Test]
    public function doctrine_hook_clears_without_rollback_when_no_transaction(): void
    {
        if (!interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
            $this->markTestSkipped('doctrine/orm not installed');
        }

        $connection = new FakeDoctrineConnection(transactionActive: false);
        $em = new FakeEntityManager($connection);
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $hook = new DoctrineResetHook($em, $this->logger);

        $hook->reset();

        $this->assertFalse($connection->rollbackCalled);
        $this->assertTrue($em->clearCalled);
    }

    #[Test]
    public function it_exposes_reset_warning_threshold(): void
    {
        $manager = new ResetManager(new BareKernel(), $this->logger, null, resetWarningMs: 75);

        $this->assertSame(75, $manager->getResetWarningMs());
    }
}
