<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Integration;

require_once __DIR__ . '/IntegrationTestDoubles.php';

use Octo\SymfonyRealtime\RealtimeMetrics;
use Octo\SymfonyRealtime\RealtimeServerAdapter;
use Octo\SymfonyRealtime\WebSocketContext;
use Octo\SymfonyRealtime\WebSocketHandler;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: Realtime server adapter.
 *
 * Verifies:
 * - HTTP/WS routing by RealtimeServerAdapter
 * - WebSocket lifecycle (open, message, close)
 * - Max lifetime configuration
 *
 * Requirements: 16.15
 */
final class RealtimeIntegrationTest extends TestCase
{
    public function testHttpRequestRoutedToHttpAdapter(): void
    {
        $httpCalled = false;
        $httpAdapter = static function (object $request, object $response) use (&$httpCalled): void {
            $httpCalled = true;
            $response->status(200);
            $response->end('HTTP OK');
        };

        $wsHandler = $this->createMock(WebSocketHandler::class);
        $wsHandler->expects(self::never())->method('onOpen');

        $logger = new IntegrationSpyLogger();
        $adapter = new RealtimeServerAdapter(
            httpAdapter: $httpAdapter,
            wsHandler: $wsHandler,
            logger: $logger,
        );

        $request = IntegrationFakeSwooleRequest::get('/api/data');
        $response = new IntegrationFakeSwooleResponse();

        $adapter($request, $response);

        self::assertTrue($httpCalled, 'HTTP request should be routed to httpAdapter');
    }

    public function testWebSocketUpgradeRoutedToWsHandler(): void
    {
        $httpCalled = false;
        $httpAdapter = static function (object $request, object $response) use (&$httpCalled): void {
            $httpCalled = true;
        };

        $openedCtx = null;
        $wsHandler = new class($openedCtx) implements WebSocketHandler {
            private ?WebSocketContext $ctx;

            public function __construct(?WebSocketContext &$ctx)
            {
                $this->ctx = &$ctx;
            }

            public function onOpen(WebSocketContext $ctx): void
            {
                $this->ctx = $ctx;
            }

            public function onMessage(WebSocketContext $ctx, string $data): void {}

            public function onClose(WebSocketContext $ctx): void {}
        };

        $logger = new IntegrationSpyLogger();
        $adapter = new RealtimeServerAdapter(
            httpAdapter: $httpAdapter,
            wsHandler: $wsHandler,
            logger: $logger,
        );

        $request = IntegrationFakeSwooleRequest::wsUpgrade('/ws');
        $request->fd = 42;
        $response = new IntegrationFakeSwooleResponse();

        $adapter($request, $response);

        self::assertFalse($httpCalled, 'WS upgrade should NOT be routed to httpAdapter');
        self::assertNotNull($openedCtx, 'WS handler onOpen should have been called');
        self::assertSame(42, $openedCtx->connectionId);
        self::assertNotEmpty($openedCtx->requestId);
    }

    public function testWebSocketContextSendAndClose(): void
    {
        $sentData = [];
        $closed = false;

        $wsHandler = new class($sentData, $closed) implements WebSocketHandler {
            private array $sentData;
            private bool $closed;

            public function __construct(array &$sentData, bool &$closed)
            {
                $this->sentData = &$sentData;
                $this->closed = &$closed;
            }

            public function onOpen(WebSocketContext $ctx): void
            {
                $ctx->send('welcome');
                $ctx->send('hello');
                $ctx->close();
            }

            public function onMessage(WebSocketContext $ctx, string $data): void {}

            public function onClose(WebSocketContext $ctx): void {}
        };

        $logger = new IntegrationSpyLogger();
        $adapter = new RealtimeServerAdapter(
            httpAdapter: static fn ($req, $res) => null,
            wsHandler: $wsHandler,
            logger: $logger,
        );

        $request = IntegrationFakeSwooleRequest::wsUpgrade('/ws');
        $response = new IntegrationFakeSwooleResponse();

        $adapter($request, $response);

        // send() delegates to response->push()
        self::assertSame(['welcome', 'hello'], $response->pushes);
        // close() delegates to response->close()
        self::assertTrue($response->closed);
    }

    public function testWebSocketUpgradeDetectionCaseInsensitive(): void
    {
        $httpAdapter = static fn ($req, $res) => null;
        $wsHandler = $this->createMock(WebSocketHandler::class);
        $wsHandler->expects(self::once())->method('onOpen');

        $logger = new IntegrationSpyLogger();
        $adapter = new RealtimeServerAdapter(
            httpAdapter: $httpAdapter,
            wsHandler: $wsHandler,
            logger: $logger,
        );

        // Mixed case headers
        $request = new IntegrationFakeSwooleRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Connection' => 'keep-alive, Upgrade',
            'x-request-id' => 'ws-case-test',
        ]);
        $request->fd = 1;

        $adapter($request, new IntegrationFakeSwooleResponse());
    }

    public function testNonUpgradeRequestWithUpgradeHeaderNotRouted(): void
    {
        $httpCalled = false;
        $httpAdapter = static function ($req, $res) use (&$httpCalled): void {
            $httpCalled = true;
        };

        $wsHandler = $this->createMock(WebSocketHandler::class);
        $wsHandler->expects(self::never())->method('onOpen');

        $logger = new IntegrationSpyLogger();
        $adapter = new RealtimeServerAdapter(
            httpAdapter: $httpAdapter,
            wsHandler: $wsHandler,
            logger: $logger,
        );

        // Has Upgrade header but Connection doesn't contain "upgrade"
        $request = new IntegrationFakeSwooleRequest('GET', '/ws', [
            'upgrade' => 'websocket',
            'connection' => 'keep-alive',
        ]);

        $adapter($request, new IntegrationFakeSwooleResponse());

        self::assertTrue($httpCalled, 'Request without proper Connection header should go to HTTP');
    }

    public function testMaxLifetimeConfigurable(): void
    {
        $logger = new IntegrationSpyLogger();
        $adapter = new RealtimeServerAdapter(
            httpAdapter: static fn ($req, $res) => null,
            wsHandler: null,
            logger: $logger,
            wsMaxLifetimeSeconds: 7200,
        );

        self::assertSame(7200, $adapter->getWsMaxLifetimeSeconds());
    }

    public function testMetricsTrackedOnWsConnection(): void
    {
        $metrics = new RealtimeMetrics();

        $wsHandler = new class implements WebSocketHandler {
            public function onOpen(WebSocketContext $ctx): void
            {
                $ctx->send('hi');
            }

            public function onMessage(WebSocketContext $ctx, string $data): void {}

            public function onClose(WebSocketContext $ctx): void {}
        };

        $logger = new IntegrationSpyLogger();
        $adapter = new RealtimeServerAdapter(
            httpAdapter: static fn ($req, $res) => null,
            wsHandler: $wsHandler,
            logger: $logger,
            metrics: $metrics,
        );

        $request = IntegrationFakeSwooleRequest::wsUpgrade('/ws');
        $request->fd = 1;
        $adapter($request, new IntegrationFakeSwooleResponse());

        $snapshot = $metrics->snapshot();
        self::assertSame(1, $snapshot['ws_connections_active']);
        self::assertSame(1, $snapshot['ws_messages_sent_total']);
    }

    public function testNoWsHandlerRoutesEverythingToHttp(): void
    {
        $httpCalled = false;
        $httpAdapter = static function ($req, $res) use (&$httpCalled): void {
            $httpCalled = true;
        };

        $logger = new IntegrationSpyLogger();
        $adapter = new RealtimeServerAdapter(
            httpAdapter: $httpAdapter,
            wsHandler: null, // no WS handler
            logger: $logger,
        );

        // Even a WS upgrade request goes to HTTP when no handler is set
        $request = IntegrationFakeSwooleRequest::wsUpgrade('/ws');
        $adapter($request, new IntegrationFakeSwooleResponse());

        self::assertTrue($httpCalled);
    }
}
