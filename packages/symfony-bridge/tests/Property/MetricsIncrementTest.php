<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge\Tests\Property;

require_once __DIR__ . '/../Unit/TestDoubles.php';

use AsyncPlatform\RuntimePack\MetricsCollector;
use AsyncPlatform\SymfonyBridge\HttpKernelAdapter;
use AsyncPlatform\SymfonyBridge\Tests\Unit\FakeSwooleRequest;
use AsyncPlatform\SymfonyBridge\Tests\Unit\FakeSwooleResponse;
use AsyncPlatform\SymfonyBridge\Tests\Unit\LifecycleTrackingKernel;
use AsyncPlatform\SymfonyBridge\Tests\Unit\SpyLogger;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Property 18: Metrics increment after each request
 *
 * **Validates: Requirements 5.1, 5.2**
 *
 * For any sequence of N requests treated by the bridge, the counter
 * symfony_requests_total SHALL equal N, and the RSS memory SHALL be
 * measured after each reset.
 */
final class MetricsIncrementTest extends TestCase
{
    use TestTrait;

    #[Test]
    public function requests_total_equals_n_after_n_requests(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(1, 20),
        )->then(function (int $n): void {
            $kernel = new LifecycleTrackingKernel();
            $logger = new SpyLogger();
            $metrics = new MetricsCollector();
            $adapter = new HttpKernelAdapter(
                kernel: $kernel,
                logger: $logger,
                metricsCollector: $metrics,
                memoryWarningThreshold: \PHP_INT_MAX,
            );

            for ($i = 0; $i < $n; $i++) {
                $adapter(
                    FakeSwooleRequest::withRequestId("pbt-metrics-$i"),
                    new FakeSwooleResponse(),
                );
            }

            $snapshot = $adapter->getMetricsBridge()->snapshot();

            // symfony_requests_total must equal N
            self::assertSame(
                $n,
                $snapshot['symfony_requests_total'],
                "After {$n} requests, symfony_requests_total must be {$n}"
            );

            // Request duration sum must be positive
            self::assertGreaterThan(
                0.0,
                $snapshot['symfony_request_duration_sum_ms'],
                'Request duration sum must be positive after requests'
            );

            // Memory RSS must have been measured (> 0)
            self::assertGreaterThan(
                0,
                $snapshot['memory_rss_after_reset_bytes'],
                'Memory RSS must be measured after each reset'
            );

            // Request count on the adapter must match
            self::assertSame(
                $n,
                $adapter->getRequestCount(),
                "Adapter request count must be {$n}"
            );
        });
    }
}
