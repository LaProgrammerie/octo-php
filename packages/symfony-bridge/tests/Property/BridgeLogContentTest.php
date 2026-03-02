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
use Symfony\Component\HttpFoundation\Response;

/**
 * Property 17: Bridge log content invariant
 *
 * **Validates: Requirements 6.2, 6.3**
 *
 * For any request treated by the bridge, all logs emitted SHALL contain the field
 * component="symfony_bridge", and the end-of-request log SHALL contain the fields
 * request_id, status_code, duration_ms, and exception_class (if applicable).
 */
final class BridgeLogContentTest extends TestCase
{
    use TestTrait;

    #[Test]
    public function end_of_request_log_contains_required_fields(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(200, 599),
            Generators::elements(['req-aaa', 'req-bbb', 'req-ccc', 'req-123', 'req-xyz']),
            Generators::bool(),
        )->then(function (int $statusCode, string $requestId, bool $throwException): void {
            $exception = $throwException ? new \RuntimeException('test') : null;
            $response = new Response('body', $statusCode);

            $kernel = new LifecycleTrackingKernel($response, $exception);
            $logger = new SpyLogger();
            $adapter = new HttpKernelAdapter(
                kernel: $kernel,
                logger: $logger,
                metricsCollector: new MetricsCollector(),
                memoryWarningThreshold: \PHP_INT_MAX,
            );

            $adapter(
                FakeSwooleRequest::withRequestId($requestId),
                new FakeSwooleResponse(),
            );

            // Find the end-of-request log
            $completedLogs = \array_filter($logger->logs, fn(array $log) =>
                $log['level'] === 'info' && \str_contains($log['message'], 'Request completed'));

            self::assertNotEmpty(
                $completedLogs,
                'End-of-request log must be emitted'
            );

            $log = \array_values($completedLogs)[0];

            // Required fields
            self::assertSame(
                $requestId,
                $log['context']['request_id'],
                'Log must contain request_id'
            );
            self::assertArrayHasKey(
                'status_code',
                $log['context'],
                'Log must contain status_code'
            );
            self::assertArrayHasKey(
                'duration_ms',
                $log['context'],
                'Log must contain duration_ms'
            );
            self::assertIsFloat(
                $log['context']['duration_ms'],
                'duration_ms must be a float'
            );
            self::assertSame(
                'symfony_bridge',
                $log['context']['component'],
                'Log must contain component=symfony_bridge'
            );

            // exception_class present only when exception was thrown
            if ($throwException) {
                self::assertArrayHasKey(
                    'exception_class',
                    $log['context'],
                    'Log must contain exception_class when exception was thrown'
                );
                self::assertSame(\RuntimeException::class, $log['context']['exception_class']);
            } else {
                self::assertArrayNotHasKey(
                    'exception_class',
                    $log['context'],
                    'Log must not contain exception_class when no exception'
                );
            }
        });
    }
}
