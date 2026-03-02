<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge\Tests\Property;

require_once __DIR__ . '/../Unit/TestDoubles.php';

use AsyncPlatform\RuntimePack\MetricsCollector;
use AsyncPlatform\SymfonyBridge\MetricsBridge;
use AsyncPlatform\SymfonyBridge\ResetManager;
use AsyncPlatform\SymfonyBridge\Tests\Unit\SpyLogger;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Property 7: Reset duration measured and logged
 *
 * **Validates: Requirements 4.11, 4.12, 4.14**
 *
 * For any reset executed by the ResetManager, the duration SHALL be measured and
 * recorded as a metric (symfony_reset_duration_ms), a debug log SHALL be emitted
 * with the request_id and the duration, and if the duration exceeds the configurable
 * threshold (ASYNC_PLATFORM_SYMFONY_RESET_WARNING_MS), a warning log SHALL be emitted.
 */
final class ResetDurationTest extends TestCase
{
    use TestTrait;

    #[Test]
    public function reset_duration_is_always_measured_and_logged(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(0, 5000),   // sleepUs: 0-5ms simulated reset time
            Generators::choose(1, 200),    // thresholdMs: 1-200ms warning threshold
        )->then(function (int $sleepUs, int $thresholdMs): void {
            $logger = new SpyLogger();
            $metricsBridge = new MetricsBridge(new MetricsCollector());
            $kernel = new ConfigurableSlowKernel($sleepUs);
            $manager = new ResetManager($kernel, $logger, $metricsBridge, resetWarningMs: $thresholdMs);

            $requestId = 'pbt-dur-' . $sleepUs . '-' . $thresholdMs;
            $manager->reset($requestId);

            // 1. Debug log must always be emitted with request_id and duration
            $debugLogs = array_filter(
                $logger->logs,
                fn(array $log) => $log['level'] === 'debug'
                && str_contains($log['message'], 'Reset completed'),
            );
            self::assertNotEmpty($debugLogs, 'Debug log must always be emitted');

            $debugLog = array_values($debugLogs)[0];
            self::assertSame($requestId, $debugLog['context']['request_id']);
            self::assertArrayHasKey('reset_duration_ms', $debugLog['context']);
            $loggedDuration = $debugLog['context']['reset_duration_ms'];
            self::assertIsFloat($loggedDuration);
            self::assertGreaterThanOrEqual(0.0, $loggedDuration);

            // 2. Metric must be recorded
            $snapshot = $metricsBridge->snapshot();
            self::assertGreaterThan(0.0, $snapshot['symfony_reset_duration_sum_ms']);

            // 3. Warning log: present iff duration > threshold
            $warningLogs = array_filter(
                $logger->logs,
                fn(array $log) => $log['level'] === 'warning'
                && str_contains($log['message'], 'Reset duration exceeded threshold'),
            );

            if ($loggedDuration > $thresholdMs) {
                self::assertNotEmpty(
                    $warningLogs,
                    "Warning must be logged when duration ({$loggedDuration}ms) > threshold ({$thresholdMs}ms)",
                );
                $warningLog = array_values($warningLogs)[0];
                self::assertSame($requestId, $warningLog['context']['request_id']);
                self::assertSame($thresholdMs, $warningLog['context']['threshold_ms']);
                self::assertArrayHasKey('reset_duration_ms', $warningLog['context']);
            } else {
                self::assertEmpty(
                    $warningLogs,
                    "No warning when duration ({$loggedDuration}ms) <= threshold ({$thresholdMs}ms)",
                );
            }
        });
    }
}

/**
 * Kernel with configurable reset delay for PBT duration testing.
 */
final class ConfigurableSlowKernel implements HttpKernelInterface, ResetInterface
{
    public function __construct(private readonly int $sleepUs)
    {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response('OK');
    }

    public function reset(): void
    {
        if ($this->sleepUs > 0) {
            usleep($this->sleepUs);
        }
    }
}
