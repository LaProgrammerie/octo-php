<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use Octo\RuntimePack\HealthController;
use Octo\RuntimePack\JsonLogger;
use Octo\RuntimePack\MetricsCollector;
use Octo\RuntimePack\ReloadPolicy;
use Octo\RuntimePack\RequestHandler;
use Octo\RuntimePack\RequestIdMiddleware;
use Octo\RuntimePack\ServerConfig;
use Octo\RuntimePack\WorkerLifecycle;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function is_array;
use function is_resource;

/**
 * Fake request object mimicking OpenSwoole\Http\Request for unit testing.
 */
final class FakeRequest
{
    /** @var array<string, string> */
    public array $header = [];

    /** @var array<string, mixed> */
    public array $server = [];

    public function __construct(string $method = 'GET', string $uri = '/', array $headers = [])
    {
        $this->server = [
            'request_method' => $method,
            'request_uri' => $uri,
        ];
        $this->header = $headers;
    }
}

/**
 * Fake response that tracks whether end() was called and captures all state.
 * Supports detecting double-end attempts.
 */
final class FakeHandlerResponse
{
    public int $statusCode = 200;

    /** @var array<string, string> */
    public array $headers = [];
    public string $body = '';
    public bool $ended = false;
    public int $endCallCount = 0;

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
        ++$this->endCallCount;
        if ($this->ended) {
            throw new RuntimeException('Response already ended');
        }
        $this->ended = true;
        $this->body = $body;
    }
}

final class RequestHandlerTest extends TestCase
{
    private ServerConfig $config;
    private WorkerLifecycle $lifecycle;
    private HealthController $healthController;
    private RequestIdMiddleware $requestIdMiddleware;
    private MetricsCollector $metrics;
    private JsonLogger $logger;

    /** @var resource */
    private $logStream;

    protected function setUp(): void
    {
        $this->config = new ServerConfig(eventLoopLagThresholdMs: 500.0);
        $this->logStream = fopen('php://memory', 'r+');
        $this->logger = new JsonLogger(production: false, stream: $this->logStream);
        $this->metrics = new MetricsCollector();
        $reloadPolicy = new ReloadPolicy($this->config, $this->logger);

        $this->lifecycle = new WorkerLifecycle(
            $this->config,
            $reloadPolicy,
            $this->metrics,
            $this->logger,
        );
        // Ensure tick is fresh for readyz
        $this->lifecycle->tick();

        $this->healthController = new HealthController($this->lifecycle);
        $this->requestIdMiddleware = new RequestIdMiddleware($this->logger);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->logStream)) {
            fclose($this->logStream);
        }
    }

    // =====================================================================
    // 10.2: Routing — /healthz
    // =====================================================================

    public function testRoutingHealthzReturns200Alive(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/healthz');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertSame(200, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('alive', $decoded['status']);
    }

    public function testRoutingHealthzSetsContentTypeAndCacheControl(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/healthz');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    // =====================================================================
    // 10.2: Routing — /readyz
    // =====================================================================

    public function testRoutingReadyzReturns200WhenReady(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/readyz');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertSame(200, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('ready', $decoded['status']);
    }

    public function testRoutingReadyzReturns503DuringShutdown(): void
    {
        $this->lifecycle->startShutdown();
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/readyz');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertSame(503, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('shutting_down', $decoded['status']);
    }

    // =====================================================================
    // 10.2: Routing — app routes
    // =====================================================================

    public function testRoutingAppRouteCallsAppHandler(): void
    {
        $called = false;
        $handler = $this->createHandler(static function (object $req, object $res) use (&$called): void {
            $called = true;
            $res->status(200);
            $res->end('ok');
        });

        $request = new FakeRequest('GET', '/api/users');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertTrue($called);
        self::assertSame('ok', $response->body);
    }

    // =====================================================================
    // 10.1 + Property 11: X-Request-Id always set
    // =====================================================================

    public function testXRequestIdAlwaysSetOnAppRoute(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/api/test');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertArrayHasKey('X-Request-Id', $response->headers);
        self::assertNotEmpty($response->headers['X-Request-Id']);
    }

    public function testXRequestIdAlwaysSetOnHealthz(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/healthz');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertArrayHasKey('X-Request-Id', $response->headers);
        self::assertNotEmpty($response->headers['X-Request-Id']);
    }

    public function testXRequestIdAlwaysSetOnShutdown503(): void
    {
        $this->lifecycle->startShutdown();
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/api/test');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertArrayHasKey('X-Request-Id', $response->headers);
        self::assertNotEmpty($response->headers['X-Request-Id']);
        self::assertSame(503, $response->statusCode);
    }

    public function testXRequestIdPropagatedFromIncomingHeader(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/api/test', ['x-request-id' => 'my-trace-id-123']);
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertSame('my-trace-id-123', $response->headers['X-Request-Id']);
    }

    // =====================================================================
    // 10.3: Shutdown 503 refusal
    // =====================================================================

    public function testShutdown503ForNonHealthRoutes(): void
    {
        $this->lifecycle->startShutdown();
        $handler = $this->createHandler();
        $request = new FakeRequest('POST', '/api/orders');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertSame(503, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('Server shutting down', $decoded['error']);
        self::assertSame('application/json', $response->headers['Content-Type']);
    }

    public function testShutdown503DoesNotCallBeginRequest(): void
    {
        $this->lifecycle->startShutdown();
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/api/test');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        // inflightScopes should remain 0 — beginRequest was never called
        self::assertSame(0, $this->lifecycle->getInflightScopes());
    }

    public function testShutdownAllowsHealthz(): void
    {
        $this->lifecycle->startShutdown();
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/healthz');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertSame(200, $response->statusCode);
    }

    public function testShutdownAllowsReadyz(): void
    {
        $this->lifecycle->startShutdown();
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/readyz');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        // readyz returns 503 during shutdown, but it's still served (not refused)
        self::assertSame(503, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('shutting_down', $decoded['status']);
    }

    // =====================================================================
    // 10.5: Access log NDJSON
    // =====================================================================

    public function testAccessLogWrittenForAppRoute(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/api/users');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        $entries = $this->getLogEntries();
        $accessLog = $this->findAccessLog($entries, '/api/users');

        self::assertNotNull($accessLog, 'Access log entry not found');
        self::assertSame('GET', $accessLog['extra']['method']);
        self::assertSame('/api/users', $accessLog['extra']['path']);
        self::assertSame(200, $accessLog['extra']['status_code']);
        self::assertArrayHasKey('duration_ms', $accessLog['extra']);
        // request_id is a top-level field set via withRequestId(), not in extra
        self::assertNotNull($accessLog['request_id']);
    }

    public function testAccessLogWrittenForShutdown503(): void
    {
        $this->lifecycle->startShutdown();
        $handler = $this->createHandler();
        $request = new FakeRequest('POST', '/api/orders');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        $entries = $this->getLogEntries();
        $accessLog = $this->findAccessLog($entries, '/api/orders');

        self::assertNotNull($accessLog, 'Access log should be written even for 503 shutdown');
        self::assertSame(503, $accessLog['extra']['status_code']);
    }

    public function testAccessLogWrittenForHealthz(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/healthz');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        $entries = $this->getLogEntries();
        $accessLog = $this->findAccessLog($entries, '/healthz');

        self::assertNotNull($accessLog, 'Access log should be written for healthz');
        self::assertSame(200, $accessLog['extra']['status_code']);
    }

    public function testAccessLogContainsDurationMs(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/api/test');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        $entries = $this->getLogEntries();
        $accessLog = $this->findAccessLog($entries, '/api/test');

        self::assertNotNull($accessLog);
        self::assertIsNumeric($accessLog['extra']['duration_ms']);
        self::assertGreaterThanOrEqual(0, $accessLog['extra']['duration_ms']);
    }

    // =====================================================================
    // 10.6: Metrics
    // =====================================================================

    public function testMetricsIncrementedForAppRoute(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/api/test');
        $response = new FakeHandlerResponse();

        $snapshot = $this->metrics->snapshot();
        self::assertSame(0, $snapshot['requests_total']);

        $handler->handle($request, $response);

        $snapshot = $this->metrics->snapshot();
        self::assertSame(1, $snapshot['requests_total']);
        self::assertSame(1, $snapshot['request_duration_ms']['count']);
    }

    public function testMetricsIncrementedForHealthz(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/healthz');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        $snapshot = $this->metrics->snapshot();
        self::assertSame(1, $snapshot['requests_total']);
    }

    public function testMetricsIncrementedForShutdown503(): void
    {
        $this->lifecycle->startShutdown();
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/api/test');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        $snapshot = $this->metrics->snapshot();
        self::assertSame(1, $snapshot['requests_total']);
    }

    // =====================================================================
    // 10.8: Exception handling → 500
    // =====================================================================

    public function testExceptionInHandlerReturns500(): void
    {
        $handler = $this->createHandler(static function (object $req, object $res): void {
            throw new RuntimeException('Something went wrong');
        });

        $request = new FakeRequest('GET', '/api/fail');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertSame(500, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertSame('Internal Server Error', $decoded['error']);
        self::assertSame('application/json', $response->headers['Content-Type']);
    }

    public function testExceptionLogsErrorWithoutDetailsInProd(): void
    {
        // Recreate with production mode
        $prodConfig = new ServerConfig(production: true, eventLoopLagThresholdMs: 500.0);
        $logStream = fopen('php://memory', 'r+');
        $prodLogger = new JsonLogger(production: true, stream: $logStream);
        $metrics = new MetricsCollector();
        $reloadPolicy = new ReloadPolicy($prodConfig, $prodLogger);
        $lifecycle = new WorkerLifecycle($prodConfig, $reloadPolicy, $metrics, $prodLogger);
        $lifecycle->tick();
        $healthController = new HealthController($lifecycle);
        $requestIdMiddleware = new RequestIdMiddleware($prodLogger);

        $handler = new RequestHandler(
            health: $healthController,
            requestId: $requestIdMiddleware,
            logger: $prodLogger,
            metrics: $metrics,
            config: $prodConfig,
            lifecycle: $lifecycle,
            appHandler: static function (object $req, object $res): void {
                throw new RuntimeException('Secret database password leaked');
            },
        );

        $request = new FakeRequest('GET', '/api/fail');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        rewind($logStream);
        $logOutput = stream_get_contents($logStream);
        fclose($logStream);

        // Error log should NOT contain the actual exception message in prod
        self::assertStringNotContainsString('Secret database password leaked', $logOutput);
        self::assertStringContainsString('Internal Server Error', $logOutput);
    }

    public function testExceptionLogsErrorWithDetailsInDev(): void
    {
        $handler = $this->createHandler(static function (object $req, object $res): void {
            throw new RuntimeException('Debug info for dev');
        });

        $request = new FakeRequest('GET', '/api/fail');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        $logOutput = $this->getLogOutput();
        self::assertStringContainsString('Debug info for dev', $logOutput);
    }

    public function testExceptionHandlerDoesNotCrashIfResponseAlreadySent(): void
    {
        $handler = $this->createHandler(static function (object $req, object $res): void {
            $res->status(200);
            $res->end('partial response');

            throw new RuntimeException('Error after response sent');
        });

        $request = new FakeRequest('GET', '/api/fail');
        $response = new FakeHandlerResponse();

        // Should not throw — the handler catches the double-end gracefully
        $handler->handle($request, $response);

        // The original response body is preserved (first end() wins)
        self::assertSame('partial response', $response->body);
    }

    // =====================================================================
    // Version headers stripped
    // =====================================================================

    public function testVersionHeadersStripped(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/api/test');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertSame('', $response->headers['Server']);
        self::assertSame('', $response->headers['X-Powered-By']);
    }

    public function testVersionHeadersStrippedOnHealthz(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/healthz');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertSame('', $response->headers['Server']);
        self::assertSame('', $response->headers['X-Powered-By']);
    }

    public function testVersionHeadersStrippedOnShutdown503(): void
    {
        $this->lifecycle->startShutdown();
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/api/test');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        self::assertSame('', $response->headers['Server']);
        self::assertSame('', $response->headers['X-Powered-By']);
    }

    // =====================================================================
    // 10.7: shouldExit — reload check (only for app routes)
    // =====================================================================

    public function testReloadCheckNotCalledForHealthz(): void
    {
        // With maxRequests=1, a single app request would trigger reload.
        // But healthz should NOT trigger reload.
        $config = new ServerConfig(
            maxRequests: 1,
            workerRestartMinInterval: 0,
            eventLoopLagThresholdMs: 500.0,
        );
        $logStream = fopen('php://memory', 'r+');
        $logger = new JsonLogger(production: false, stream: $logStream);
        $metrics = new MetricsCollector();
        $reloadPolicy = new ReloadPolicy($config, $logger);
        $lifecycle = new WorkerLifecycle($config, $reloadPolicy, $metrics, $logger);
        $lifecycle->tick();
        $healthController = new HealthController($lifecycle);
        $requestIdMiddleware = new RequestIdMiddleware($logger);

        $handler = new RequestHandler(
            health: $healthController,
            requestId: $requestIdMiddleware,
            logger: $logger,
            metrics: $metrics,
            config: $config,
            lifecycle: $lifecycle,
            appHandler: static fn (object $req, object $res) => $res->end('ok'),
        );

        $request = new FakeRequest('GET', '/healthz');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        // shouldExit should be false — healthz doesn't trigger afterRequest
        self::assertFalse($lifecycle->shouldExit());

        fclose($logStream);
    }

    // =====================================================================
    // beginRequest / endRequest lifecycle
    // =====================================================================

    public function testBeginEndRequestCalledForAppRoutes(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/api/test');
        $response = new FakeHandlerResponse();

        self::assertSame(0, $this->lifecycle->getInflightScopes());

        $handler->handle($request, $response);

        // After handle completes, endRequest should have been called
        self::assertSame(0, $this->lifecycle->getInflightScopes());
    }

    public function testEndRequestCalledEvenOnException(): void
    {
        $handler = $this->createHandler(static function (object $req, object $res): void {
            throw new RuntimeException('boom');
        });

        $request = new FakeRequest('GET', '/api/fail');
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        // endRequest must be called in finally — inflightScopes back to 0
        self::assertSame(0, $this->lifecycle->getInflightScopes());
    }

    // =====================================================================
    // Access log component and request_id
    // =====================================================================

    public function testAccessLogHasHttpComponentAndRequestId(): void
    {
        $handler = $this->createHandler();
        $request = new FakeRequest('GET', '/api/test', ['x-request-id' => 'trace-abc']);
        $response = new FakeHandlerResponse();

        $handler->handle($request, $response);

        $entries = $this->getLogEntries();
        $accessLog = $this->findAccessLog($entries, '/api/test');

        self::assertNotNull($accessLog);
        self::assertSame('http', $accessLog['component']);
        self::assertSame('trace-abc', $accessLog['request_id']);
    }

    private function createHandler(?callable $appHandler = null): RequestHandler
    {
        return new RequestHandler(
            health: $this->healthController,
            requestId: $this->requestIdMiddleware,
            logger: $this->logger,
            metrics: $this->metrics,
            config: $this->config,
            lifecycle: $this->lifecycle,
            appHandler: $appHandler ?? static function (object $req, object $res): void {
                $res->status(200);
                $res->header('Content-Type', 'application/json');
                $res->end('{"ok":true}');
            },
        );
    }

    private function getLogOutput(): string
    {
        rewind($this->logStream);

        return stream_get_contents($this->logStream);
    }

    /**
     * Parse all NDJSON log lines from the log stream.
     *
     * @return list<array<string, mixed>>
     */
    private function getLogEntries(): array
    {
        $output = $this->getLogOutput();
        $lines = array_filter(explode("\n", $output));
        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Find the access log entry for a given path (the one with empty message and path in extra).
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return null|array<string, mixed>
     */
    private function findAccessLog(array $entries, string $path): ?array
    {
        foreach ($entries as $entry) {
            if (
                isset($entry['extra']['path'])
                && $entry['extra']['path'] === $path
                && $entry['message'] === ''
            ) {
                return $entry;
            }
        }

        return null;
    }
}
