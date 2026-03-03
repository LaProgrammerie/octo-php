<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Property;

require_once __DIR__ . '/../Unit/TestDoubles.php';

use const PHP_INT_MAX;

use DomainException;
use Eris\Generators;
use Eris\TestTrait;
use InvalidArgumentException;
use LogicException;
use Octo\RuntimePack\MetricsCollector;
use Octo\SymfonyBridge\HttpKernelAdapter;
use Octo\SymfonyBridge\Tests\Unit\FakeSwooleRequest;
use Octo\SymfonyBridge\Tests\Unit\FakeSwooleResponse;
use Octo\SymfonyBridge\Tests\Unit\LifecycleTrackingKernel;
use Octo\SymfonyBridge\Tests\Unit\SpyLogger;
use OverflowException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RangeException;
use RuntimeException;
use UnderflowException;

use function count;

/**
 * Property 9: Exception handling produces valid HTTP response.
 *
 * **Validates: Requirements 7.1, 7.3, 7.4, 7.5**
 *
 * For any exception thrown by the HttpKernel, the HttpKernelAdapter SHALL
 * intercept the exception, return an HTTP 500 response (JSON generic in prod),
 * increment the symfony_exceptions_total counter, and never let the exception
 * bubble up to the runtime pack.
 */
final class ExceptionHandlingTest extends TestCase
{
    use TestTrait;

    private const EXCEPTION_TYPES = [
        RuntimeException::class,
        LogicException::class,
        InvalidArgumentException::class,
        OverflowException::class,
        UnderflowException::class,
        DomainException::class,
        RangeException::class,
    ];

    #[Test]
    public function anyExceptionProduces500ResponseAndIncrementsCounter(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(0, count(self::EXCEPTION_TYPES) - 1),
            Generators::elements(['error A', 'something broke', 'null ref', 'timeout', 'db fail']),
        )->then(static function (int $exceptionIdx, string $message): void {
            $exceptionClass = self::EXCEPTION_TYPES[$exceptionIdx];
            $exception = new $exceptionClass($message);

            $kernel = new LifecycleTrackingKernel(exceptionToThrow: $exception);
            $logger = new SpyLogger();
            $metrics = new MetricsCollector();
            $adapter = new HttpKernelAdapter(
                kernel: $kernel,
                logger: $logger,
                metricsCollector: $metrics,
                debug: false,
                memoryWarningThreshold: PHP_INT_MAX,
            );

            $request = FakeSwooleRequest::withRequestId('pbt-exc-' . $exceptionIdx);
            $swooleResponse = new FakeSwooleResponse();

            // Must NEVER throw — invariant "no exception bubbles to runtime pack"
            $adapter($request, $swooleResponse);

            // Response must be 500
            self::assertSame(
                500,
                $swooleResponse->statusCode,
                "Exception {$exceptionClass} must produce HTTP 500",
            );

            // Response must have been sent
            self::assertTrue(
                $swooleResponse->endCalled,
                'Response must be sent even on exception',
            );

            // Exception counter must be incremented
            $snapshot = $adapter->getMetricsBridge()->snapshot();
            self::assertSame(
                1,
                $snapshot['symfony_exceptions_total'],
                "Exception counter must be incremented for {$exceptionClass}",
            );

            // Prod mode: response body must be generic JSON without stacktrace
            $body = $swooleResponse->endContent;
            $decoded = json_decode($body, true);
            self::assertSame(
                'Internal Server Error',
                $decoded['error'] ?? null,
                'Prod mode: response must be generic JSON',
            );

            // Must not contain the actual exception message (security)
            self::assertStringNotContainsString(
                $message,
                $body,
                'Prod mode: response must not contain exception details',
            );

            // Reset must still have been executed
            self::assertContains(
                'reset',
                $kernel->calls,
                'Reset must always execute even after exception',
            );
        });
    }
}
