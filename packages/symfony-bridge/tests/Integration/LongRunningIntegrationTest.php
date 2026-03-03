<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Integration;

require_once __DIR__ . '/IntegrationTestDoubles.php';

use const PHP_INT_MAX;

use Octo\RuntimePack\MetricsCollector;
use Octo\SymfonyBridge\HttpKernelAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

use function sprintf;

/**
 * Integration test: long-running stability.
 *
 * Sends 1000+ sequential requests through the bridge, verifying:
 * - Reset called after each request (counting mock)
 * - No abnormal memory growth (RSS req 1000 ≤ 2× RSS req 10)
 * - All responses correct
 *
 * Requirements: 16.4
 */
final class LongRunningIntegrationTest extends TestCase
{
    private const REQUEST_COUNT = 1000;

    public function testThousandRequestsWithResetAndMemoryStability(): void
    {
        $kernel = new MiniHttpKernel(new Response('OK', 200));
        $resetHook = new CountingResetHook();
        $logger = new IntegrationSpyLogger();
        $metrics = new MetricsCollector();

        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: $metrics,
            memoryWarningThreshold: PHP_INT_MAX, // suppress warnings
        );
        $adapter->getResetManager()->addHook($resetHook);

        $memoryAtReq10 = null;
        $memoryAtReq1000 = null;

        for ($i = 1; $i <= self::REQUEST_COUNT; ++$i) {
            $request = IntegrationFakeSwooleRequest::get('/', "long-run-{$i}");
            $response = new IntegrationFakeSwooleResponse();

            $adapter($request, $response);

            // Verify each response is correct
            self::assertSame(200, $response->statusCode, "Request {$i}: expected 200");
            self::assertSame('OK', $response->endContent, "Request {$i}: expected body 'OK'");

            if ($i === 10) {
                $memoryAtReq10 = memory_get_usage(true);
            }
            if ($i === self::REQUEST_COUNT) {
                $memoryAtReq1000 = memory_get_usage(true);
            }
        }

        // Reset called after every request (kernel + hook)
        self::assertSame(self::REQUEST_COUNT, $kernel->handleCount);
        self::assertSame(self::REQUEST_COUNT, $kernel->terminateCount);
        self::assertSame(self::REQUEST_COUNT, $kernel->resetCount);
        self::assertSame(self::REQUEST_COUNT, $resetHook->resetCount);

        // Memory stability: RSS at req 1000 ≤ 2× RSS at req 10
        self::assertNotNull($memoryAtReq10);
        self::assertNotNull($memoryAtReq1000);
        self::assertLessThanOrEqual(
            $memoryAtReq10 * 2,
            $memoryAtReq1000,
            sprintf(
                'Memory grew abnormally: req10=%d bytes, req1000=%d bytes (ratio=%.2f)',
                $memoryAtReq10,
                $memoryAtReq1000,
                $memoryAtReq1000 / $memoryAtReq10,
            ),
        );

        // Metrics reflect total requests
        $snapshot = $adapter->getMetricsBridge()->snapshot();
        self::assertSame(self::REQUEST_COUNT, $snapshot['symfony_requests_total']);
    }

    public function testRequestCounterIncrements(): void
    {
        $kernel = new MiniHttpKernel();
        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
        );

        for ($i = 0; $i < 50; ++$i) {
            $adapter(IntegrationFakeSwooleRequest::get('/', "cnt-{$i}"), new IntegrationFakeSwooleResponse());
        }

        self::assertSame(50, $adapter->getRequestCount());
    }
}
