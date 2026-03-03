<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use Octo\RuntimePack\HealthController;
use Octo\RuntimePack\JsonLogger;
use Octo\RuntimePack\MetricsCollector;
use Octo\RuntimePack\ReloadPolicy;
use Octo\RuntimePack\ServerConfig;
use Octo\RuntimePack\WorkerLifecycle;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function is_resource;

/**
 * Fake response object mimicking OpenSwoole\Http\Response for unit testing.
 * Captures status code, headers, and body for assertions.
 */
final class FakeResponse
{
    public int $statusCode = 200;

    /** @var array<string, string> */
    public array $headers = [];
    public string $body = '';

    public function status(int $code): void
    {
        $this->statusCode = $code;
    }

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function end(string $body = ''): void
    {
        $this->body = $body;
    }
}

final class HealthControllerTest extends TestCase
{
    private WorkerLifecycle $lifecycle;
    private HealthController $controller;

    /** @var resource */
    private $logStream;

    protected function setUp(): void
    {
        $config = new ServerConfig(eventLoopLagThresholdMs: 500.0);
        $this->logStream = fopen('php://memory', 'r+');
        $logger = new JsonLogger(production: false, stream: $this->logStream);
        $metrics = new MetricsCollector();
        $reloadPolicy = new ReloadPolicy($config, $logger);

        $this->lifecycle = new WorkerLifecycle($config, $reloadPolicy, $metrics, $logger);
        $this->controller = new HealthController($this->lifecycle);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->logStream)) {
            fclose($this->logStream);
        }
    }

    // --- 5.5: healthz() ---

    public function testHealthzReturns200WithAliveStatus(): void
    {
        $response = new FakeResponse();
        $this->controller->healthz($response);

        self::assertSame(200, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('alive', $decoded['status']);
    }

    public function testHealthzSetsContentTypeJson(): void
    {
        $response = new FakeResponse();
        $this->controller->healthz($response);

        self::assertSame('application/json', $response->headers['Content-Type']);
    }

    public function testHealthzSetsCacheControlNoStore(): void
    {
        $response = new FakeResponse();
        $this->controller->healthz($response);

        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    public function testHealthzReturns200EvenDuringShutdown(): void
    {
        $this->lifecycle->startShutdown();

        $response = new FakeResponse();
        $this->controller->healthz($response);

        self::assertSame(200, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('alive', $decoded['status']);
    }

    // --- 5.6: readyz() ---

    public function testReadyzReturns200WhenReady(): void
    {
        // Ensure tick is fresh
        $this->lifecycle->tick();

        $response = new FakeResponse();
        $this->controller->readyz($response);

        self::assertSame(200, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('ready', $decoded['status']);
        self::assertArrayHasKey('event_loop_lag_ms', $decoded);
    }

    public function testReadyzSetsContentTypeJson(): void
    {
        $this->lifecycle->tick();

        $response = new FakeResponse();
        $this->controller->readyz($response);

        self::assertSame('application/json', $response->headers['Content-Type']);
    }

    public function testReadyzSetsCacheControlNoStore(): void
    {
        $this->lifecycle->tick();

        $response = new FakeResponse();
        $this->controller->readyz($response);

        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    public function testReadyzReturns503WhenShuttingDown(): void
    {
        $this->lifecycle->tick();
        $this->lifecycle->startShutdown();

        $response = new FakeResponse();
        $this->controller->readyz($response);

        self::assertSame(503, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('shutting_down', $decoded['status']);
    }

    public function testReadyzReturns503WhenTickStale(): void
    {
        // Set lastLoopTickAt to 3 seconds ago via reflection
        $ref = new ReflectionProperty(WorkerLifecycle::class, 'lastLoopTickAt');
        $ref->setValue($this->lifecycle, microtime(true) - 3.0);

        $response = new FakeResponse();
        $this->controller->readyz($response);

        self::assertSame(503, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('event_loop_stale', $decoded['status']);
    }

    public function testReadyzReturns503WhenLagExceedsThreshold(): void
    {
        // Ensure tick is fresh (not stale)
        $this->lifecycle->tick();

        // Set high lag via reflection
        $ref = new ReflectionProperty(WorkerLifecycle::class, 'eventLoopLagMs');
        $ref->setValue($this->lifecycle, 600.0); // > 500ms threshold

        $response = new FakeResponse();
        $this->controller->readyz($response);

        self::assertSame(503, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('event_loop_lagging', $decoded['status']);
        self::assertSame(600.0, $decoded['lag_ms']);
    }

    public function testReadyzCheckOrderShutdownBeforeStale(): void
    {
        // Both shutdown AND stale — shutdown should win
        $this->lifecycle->startShutdown();
        $ref = new ReflectionProperty(WorkerLifecycle::class, 'lastLoopTickAt');
        $ref->setValue($this->lifecycle, microtime(true) - 3.0);

        $response = new FakeResponse();
        $this->controller->readyz($response);

        self::assertSame(503, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('shutting_down', $decoded['status']);
    }

    public function testReadyzCheckOrderStaleBeforeLag(): void
    {
        // Both stale AND lagging — stale should win
        $ref = new ReflectionProperty(WorkerLifecycle::class, 'lastLoopTickAt');
        $ref->setValue($this->lifecycle, microtime(true) - 3.0);

        $lagRef = new ReflectionProperty(WorkerLifecycle::class, 'eventLoopLagMs');
        $lagRef->setValue($this->lifecycle, 600.0);

        $response = new FakeResponse();
        $this->controller->readyz($response);

        self::assertSame(503, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('event_loop_stale', $decoded['status']);
    }

    public function testReadyzIncludesLagMsInReadyResponse(): void
    {
        $this->lifecycle->tick();

        // Set a known lag value
        $ref = new ReflectionProperty(WorkerLifecycle::class, 'eventLoopLagMs');
        $ref->setValue($this->lifecycle, 42.5);

        $response = new FakeResponse();
        $this->controller->readyz($response);

        self::assertSame(200, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame(42.5, $decoded['event_loop_lag_ms']);
    }

    public function testReadyzIncludesLagMsInLaggingResponse(): void
    {
        $this->lifecycle->tick();

        $ref = new ReflectionProperty(WorkerLifecycle::class, 'eventLoopLagMs');
        $ref->setValue($this->lifecycle, 750.0);

        $response = new FakeResponse();
        $this->controller->readyz($response);

        self::assertSame(503, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('event_loop_lagging', $decoded['status']);
        self::assertSame(750.0, $decoded['lag_ms']);
    }

    public function testReadyzReturnsValidJson(): void
    {
        $this->lifecycle->tick();

        $response = new FakeResponse();
        $this->controller->readyz($response);

        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
    }

    public function testHealthzReturnsValidJson(): void
    {
        $response = new FakeResponse();
        $this->controller->healthz($response);

        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(['status' => 'alive'], $decoded);
    }
}
