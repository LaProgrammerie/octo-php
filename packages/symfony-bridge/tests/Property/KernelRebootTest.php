<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Property;

require_once __DIR__ . '/../Unit/TestDoubles.php';

use Octo\RuntimePack\MetricsCollector;
use Octo\SymfonyBridge\HttpKernelAdapter;
use Octo\SymfonyBridge\Tests\Unit\FakeSwooleRequest;
use Octo\SymfonyBridge\Tests\Unit\FakeSwooleResponse;
use Octo\SymfonyBridge\Tests\Unit\LifecycleTrackingKernel;
use Octo\SymfonyBridge\Tests\Unit\SpyLogger;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Property 8: Kernel reboot reconstructs references
 *
 * **Validates: Requirements 4.15, 4.16, 4.17, 4.18**
 *
 * For any configuration with OCTOP_SYMFONY_KERNEL_REBOOT_EVERY > 0,
 * when the request counter reaches the configured value, the HttpKernelAdapter
 * SHALL reboot the kernel (shutdown() + boot()) and reconstruct its internal
 * references (ResetManager, RequestIdProcessor, hooks) to the new container.
 * The worker SHALL NOT be killed.
 */
final class KernelRebootTest extends TestCase
{
    use TestTrait;

    #[Test]
    public function kernel_reboot_happens_at_correct_intervals_and_rebuilds_references(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(1, 10),  // rebootEvery (M)
            Generators::choose(1, 30),  // totalRequests (N)
        )->then(function (int $rebootEvery, int $totalRequests): void {
            $kernel = new LifecycleTrackingKernel();
            $logger = new SpyLogger();
            $adapter = new HttpKernelAdapter(
                kernel: $kernel,
                logger: $logger,
                metricsCollector: new MetricsCollector(),
                kernelRebootEvery: $rebootEvery,
                memoryWarningThreshold: \PHP_INT_MAX,
            );

            $previousResetManager = $adapter->getResetManager();
            $previousProcessor = $adapter->getRequestIdProcessor();
            $rebootCount = 0;

            for ($i = 1; $i <= $totalRequests; $i++) {
                $kernel->calls = [];
                $adapter(
                    FakeSwooleRequest::withRequestId("pbt-reboot-$i"),
                    new FakeSwooleResponse(),
                );

                $requestNum = $i;
                $shouldReboot = ($requestNum % $rebootEvery) === 0;

                if ($shouldReboot) {
                    $rebootCount++;

                    // shutdown + boot must have been called
                    self::assertContains(
                        'shutdown',
                        $kernel->calls,
                        "Request #{$requestNum}: shutdown() must be called (rebootEvery={$rebootEvery})"
                    );
                    self::assertContains(
                        'boot',
                        $kernel->calls,
                        "Request #{$requestNum}: boot() must be called (rebootEvery={$rebootEvery})"
                    );

                    // References must be rebuilt (new instances)
                    self::assertNotSame(
                        $previousResetManager,
                        $adapter->getResetManager(),
                        "Request #{$requestNum}: ResetManager must be rebuilt after reboot"
                    );
                    self::assertNotSame(
                        $previousProcessor,
                        $adapter->getRequestIdProcessor(),
                        "Request #{$requestNum}: RequestIdProcessor must be rebuilt after reboot"
                    );

                    $previousResetManager = $adapter->getResetManager();
                    $previousProcessor = $adapter->getRequestIdProcessor();
                } else {
                    // No reboot: shutdown/boot must NOT be in the calls
                    self::assertNotContains(
                        'shutdown',
                        $kernel->calls,
                        "Request #{$requestNum}: shutdown() must NOT be called (rebootEvery={$rebootEvery})"
                    );
                    self::assertNotContains(
                        'boot',
                        $kernel->calls,
                        "Request #{$requestNum}: boot() must NOT be called (rebootEvery={$rebootEvery})"
                    );
                }
            }

            // Verify total reboot count
            $expectedReboots = \intdiv($totalRequests, $rebootEvery);
            self::assertSame(
                $expectedReboots,
                $rebootCount,
                "Expected {$expectedReboots} reboots for {$totalRequests} requests with rebootEvery={$rebootEvery}"
            );

            // Worker must still be alive (requestCount matches)
            self::assertSame(
                $totalRequests,
                $adapter->getRequestCount(),
                'Worker must not be killed — all requests processed'
            );
        });
    }
}
