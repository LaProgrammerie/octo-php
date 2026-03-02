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
 * Property 5: Lifecycle ordering invariant
 *
 * **Validates: Requirements 4.1, 4.2, 4.3, 9.4**
 *
 * For any request treated by the HttpKernelAdapter, the execution order SHALL be
 * strictly: HttpKernel::handle() → response writing → kernel->terminate() → ResetManager::reset().
 * If the response has already been sent by the runtime pack (408/503), response writing
 * is skipped but terminate and reset are always executed.
 */
final class LifecycleOrderingTest extends TestCase
{
    use TestTrait;

    /**
     * Scenario types:
     * 0 = normal (handle succeeds)
     * 1 = exception during handle
     * 2 = normal with various status codes
     */
    private const SCENARIO_NORMAL = 0;
    private const SCENARIO_EXCEPTION = 1;
    private const SCENARIO_VARIOUS_STATUS = 2;

    #[Test]
    public function lifecycle_order_is_invariant_for_any_scenario(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(0, 2),
            Generators::choose(200, 599),
        )->then(function (int $scenario, int $statusCode): void {
            $exception = $scenario === self::SCENARIO_EXCEPTION
                ? new \RuntimeException('PBT exception')
                : null;

            $response = $scenario === self::SCENARIO_VARIOUS_STATUS
                ? new Response('body', $statusCode)
                : new Response('OK', 200);

            $kernel = new LifecycleTrackingKernel($response, $exception);
            $logger = new SpyLogger();
            $adapter = new HttpKernelAdapter(
                kernel: $kernel,
                logger: $logger,
                metricsCollector: new MetricsCollector(),
                memoryWarningThreshold: \PHP_INT_MAX,
            );

            $request = FakeSwooleRequest::withRequestId('pbt-lifecycle-' . $scenario);
            $swooleResponse = new FakeSwooleResponse();

            // Must never throw
            $adapter($request, $swooleResponse);

            // Verify ordering invariant
            $calls = $kernel->calls;

            // handle is always the first call
            self::assertNotEmpty($calls, 'Kernel must receive at least one call');
            self::assertSame('handle', $calls[0], 'handle() must be the first call');

            // terminate must come after handle (if present)
            $handleIdx = \array_search('handle', $calls, true);
            $terminateIdx = \array_search('terminate', $calls, true);
            $resetIdx = \array_search('reset', $calls, true);

            if ($scenario !== self::SCENARIO_EXCEPTION) {
                // Normal flow: handle → terminate → reset
                self::assertNotFalse($terminateIdx, 'terminate() must be called in normal flow');
                self::assertGreaterThan($handleIdx, $terminateIdx, 'terminate() must come after handle()');
            }

            // reset is ALWAYS called (invariant)
            self::assertNotFalse($resetIdx, 'reset() must ALWAYS be called');

            if ($terminateIdx !== false) {
                self::assertGreaterThan($terminateIdx, $resetIdx, 'reset() must come after terminate()');
            }

            // Response must have been sent (end called on swoole response)
            self::assertTrue($swooleResponse->endCalled, 'Response must be sent');
        });
    }
}
