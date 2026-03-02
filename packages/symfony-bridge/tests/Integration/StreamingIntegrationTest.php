<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge\Tests\Integration;

require_once __DIR__ . '/IntegrationTestDoubles.php';

use AsyncPlatform\RuntimePack\MetricsCollector;
use AsyncPlatform\SymfonyBridge\HttpKernelAdapter;
use AsyncPlatform\SymfonyBridge\ResponseConverter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Integration test: streaming responses.
 *
 * Verifies:
 * - StreamedResponse with large volume → write() calls
 * - SSE with immediate flush
 * - Exception in callback → log + end response
 *
 * Requirements: 16.9
 */
final class StreamingIntegrationTest extends TestCase
{
    public function testStreamedResponseWithLargeVolume(): void
    {
        $chunkCount = 100;
        $chunkData = str_repeat('X', 1024); // 1KB per chunk

        $streamedResponse = new StreamedResponse(function () use ($chunkCount, $chunkData) {
            for ($i = 0; $i < $chunkCount; $i++) {
                echo $chunkData;
            }
        }, 200, ['Content-Type' => 'text/plain']);

        $kernel = new class ($streamedResponse) implements HttpKernelInterface, ResetInterface {
            public function __construct(private readonly Response $response)
            {}
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return $this->response;
            }
            public function terminate(Request $request, Response $response): void
            {}
            public function reset(): void
            {}
        };

        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
        );

        $response = new IntegrationFakeSwooleResponse();
        $adapter(IntegrationFakeSwooleRequest::get('/', 'stream-1'), $response);

        // Verify write() was called for the chunks
        $this->assertNotEmpty($response->writes, 'StreamedResponse should produce write() calls');

        // Total written content should equal chunkCount * chunkData
        $totalWritten = implode('', $response->writes);
        $this->assertSame($chunkCount * strlen($chunkData), strlen($totalWritten));

        // end('') called after streaming
        $this->assertTrue($response->endCalled);
        $this->assertSame('', $response->endContent);
    }

    public function testSseResponseWithImmediateFlush(): void
    {
        $events = [
            "data: event1\n\n",
            "data: event2\n\n",
            "data: event3\n\n",
        ];

        $sseResponse = new StreamedResponse(function () use ($events) {
            foreach ($events as $event) {
                echo $event;
            }
        }, 200, ['Content-Type' => 'text/event-stream']);

        $kernel = new class ($sseResponse) implements HttpKernelInterface, ResetInterface {
            public function __construct(private readonly Response $response)
            {}
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return $this->response;
            }
            public function terminate(Request $request, Response $response): void
            {}
            public function reset(): void
            {}
        };

        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
        );

        $response = new IntegrationFakeSwooleResponse();
        $adapter(IntegrationFakeSwooleRequest::get('/events', 'sse-1'), $response);

        // SSE headers should be set
        $this->assertSame(200, $response->statusCode);

        // SSE disables buffering
        $this->assertArrayHasKey('X-Accel-Buffering', $response->headers);
        $this->assertSame('no', $response->headers['X-Accel-Buffering']);
        $this->assertArrayHasKey('Cache-Control', $response->headers);
        $this->assertSame('no-cache', $response->headers['Cache-Control']);

        // Events should have been written
        $allWritten = implode('', $response->writes);
        foreach ($events as $event) {
            $this->assertStringContainsString(trim($event), $allWritten);
        }
    }

    public function testStreamedResponseExceptionInCallbackLogsAndEnds(): void
    {
        $streamedResponse = new StreamedResponse(function () {
            echo "chunk1";
            throw new \RuntimeException('Streaming failed');
        }, 200, ['Content-Type' => 'text/plain']);

        $kernel = new class ($streamedResponse) implements HttpKernelInterface, ResetInterface {
            public function __construct(private readonly Response $response)
            {}
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return $this->response;
            }
            public function terminate(Request $request, Response $response): void
            {}
            public function reset(): void
            {}
        };

        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
        );

        $response = new IntegrationFakeSwooleResponse();
        $adapter(IntegrationFakeSwooleRequest::get('/', 'stream-err-1'), $response);

        // Response should still be terminated
        $this->assertTrue($response->endCalled);

        // Error should be logged
        $this->assertTrue(
            $logger->hasLogMatching('error', 'StreamedResponse callback exception'),
            'Expected error log about streaming exception',
        );
    }
}
