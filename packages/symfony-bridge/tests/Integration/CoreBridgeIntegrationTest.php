<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge\Tests\Integration;

require_once __DIR__ . '/IntegrationTestDoubles.php';

use AsyncPlatform\RuntimePack\MetricsCollector;
use AsyncPlatform\SymfonyBridge\HttpKernelAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

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

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('Welcome', $response->endContent);
        $this->assertTrue($response->endCalled);
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

        $this->assertSame(1, $kernel->handleCount);
        $this->assertSame(1, $kernel->terminateCount);
        $this->assertSame(1, $kernel->resetCount);
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

        $completedLogs = array_filter($logger->logs, fn(array $log) =>
            $log['level'] === 'info' && str_contains($log['message'], 'Request completed'));
        $this->assertNotEmpty($completedLogs);

        $log = array_values($completedLogs)[0];
        $this->assertSame('trace-abc', $log['context']['request_id']);
        $this->assertSame(200, $log['context']['status_code']);
        $this->assertArrayHasKey('duration_ms', $log['context']);
        $this->assertSame('symfony_bridge', $log['context']['component']);
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
        $this->assertSame(1, $snapshot['symfony_requests_total']);
        $this->assertGreaterThan(0.0, $snapshot['symfony_request_duration_sum_ms']);
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

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('{"ok":true}', $response->endContent);
    }

    public function testExceptionReturns500InProdMode(): void
    {
        $kernel = new MiniHttpKernel();
        $kernel->setResponse(new Response('OK'));
        // Override kernel to throw
        $throwingKernel = new class implements \Symfony\Component\HttpKernel\HttpKernelInterface, \Symfony\Contracts\Service\ResetInterface {
            public int $resetCount = 0;
            public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                throw new \RuntimeException('Integration test error');
            }
            public function terminate(\Symfony\Component\HttpFoundation\Request $request, Response $response): void
            {
            }
            public function reset(): void
            {
                $this->resetCount++;
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

        $this->assertSame(500, $response->statusCode);
        $decoded = json_decode($response->endContent, true);
        $this->assertSame('Internal Server Error', $decoded['error']);
        // Reset must still be called
        $this->assertSame(1, $throwingKernel->resetCount);
    }
}
