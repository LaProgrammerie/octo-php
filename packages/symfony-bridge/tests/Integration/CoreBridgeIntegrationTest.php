<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Integration;

require_once __DIR__ . '/IntegrationTestDoubles.php';

use Octo\RuntimePack\MetricsCollector;
use Octo\SymfonyBridge\HttpKernelAdapter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Integration test: core bridge end-to-end.
 *
 * Verifies that a mini HttpKernel receives a request through the bridge
 * and produces a correct HTTP 200 response.
 *
 * Requirements: 16.3
 */
final class CoreBridgeIntegrationTest extends TestCase
{
    public function testGetRequestReturns200WithCorrectBody(): void
    {
        $kernel = new MiniHttpKernel(new Response('Welcome', 200, ['Content-Type' => 'text/html']));
        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
        );

        $request = IntegrationFakeSwooleRequest::get('/', 'int-test-1');
        $response = new IntegrationFakeSwooleResponse();

        $adapter($request, $response);

        self::assertSame(200, $response->statusCode);
        self::assertSame('Welcome', $response->endContent);
        self::assertTrue($response->endCalled);
    }

    public function testLifecycleSequenceHandleTerminateReset(): void
    {
        $kernel = new MiniHttpKernel();
        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
        );

        $adapter(IntegrationFakeSwooleRequest::get('/', 'seq-1'), new IntegrationFakeSwooleResponse());

        self::assertSame(1, $kernel->handleCount);
        self::assertSame(1, $kernel->terminateCount);
        self::assertSame(1, $kernel->resetCount);
    }

    public function testRequestIdPropagatedInLogs(): void
    {
        $kernel = new MiniHttpKernel();
        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
        );

        $adapter(IntegrationFakeSwooleRequest::get('/', 'trace-abc'), new IntegrationFakeSwooleResponse());

        $completedLogs = array_filter($logger->logs, static fn (array $log) => $log['level'] === 'info' && str_contains($log['message'], 'Request completed'));
        self::assertNotEmpty($completedLogs);

        $log = array_values($completedLogs)[0];
        self::assertSame('trace-abc', $log['context']['request_id']);
        self::assertSame(200, $log['context']['status_code']);
        self::assertArrayHasKey('duration_ms', $log['context']);
        self::assertSame('symfony_bridge', $log['context']['component']);
    }

    public function testMetricsIncrementedAfterRequest(): void
    {
        $kernel = new MiniHttpKernel();
        $logger = new IntegrationSpyLogger();
        $metrics = new MetricsCollector();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: $metrics,
        );

        $adapter(IntegrationFakeSwooleRequest::get('/', 'met-1'), new IntegrationFakeSwooleResponse());

        $snapshot = $adapter->getMetricsBridge()->snapshot();
        self::assertSame(1, $snapshot['symfony_requests_total']);
        self::assertGreaterThan(0.0, $snapshot['symfony_request_duration_sum_ms']);
    }

    public function testPostRequestWithJsonBody(): void
    {
        $kernel = new MiniHttpKernel(new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']));
        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
        );

        $request = IntegrationFakeSwooleRequest::post('/api/data', '{"name":"test"}');
        $request->header['x-request-id'] = 'post-1';
        $response = new IntegrationFakeSwooleResponse();

        $adapter($request, $response);

        self::assertSame(200, $response->statusCode);
        self::assertSame('{"ok":true}', $response->endContent);
    }

    public function testExceptionReturns500InProdMode(): void
    {
        $kernel = new MiniHttpKernel();
        $kernel->setResponse(new Response('OK'));
        // Override kernel to throw
        $throwingKernel = new class implements HttpKernelInterface, ResetInterface {
            public int $resetCount = 0;

            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                throw new RuntimeException('Integration test error');
            }

            public function terminate(Request $request, Response $response): void {}

            public function reset(): void
            {
                ++$this->resetCount;
            }
        };

        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $throwingKernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
            debug: false,
        );

        $response = new IntegrationFakeSwooleResponse();
        $adapter(IntegrationFakeSwooleRequest::get('/', 'err-1'), $response);

        self::assertSame(500, $response->statusCode);
        $decoded = json_decode($response->endContent, true);
        self::assertSame('Internal Server Error', $decoded['error']);
        // Reset must still be called
        self::assertSame(1, $throwingKernel->resetCount);
    }
}
