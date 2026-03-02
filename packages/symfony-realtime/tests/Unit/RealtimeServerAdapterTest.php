<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyRealtime\Tests\Unit;

use AsyncPlatform\SymfonyRealtime\RealtimeMetrics;
use AsyncPlatform\SymfonyRealtime\RealtimeServerAdapter;
use AsyncPlatform\SymfonyRealtime\WebSocketContext;
use AsyncPlatform\SymfonyRealtime\WebSocketHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RealtimeServerAdapterTest extends TestCase
{
    #[Test]
    public function detectsWebSocketUpgradeWithCorrectHeaders(): void
    {
        $adapter = $this->createAdapter();

        $request = new \stdClass();
        $request->header = [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
        ];
        $request->server = [];

        self::assertTrue($adapter->isWebSocketUpgrade($request));
    }

    #[Test]
    public function detectsWebSocketUpgradeCaseInsensitive(): void
    {
        $adapter = $this->createAdapter();

        $request = new \stdClass();
        $request->header = [
            'Upgrade' => 'WebSocket',
            'Connection' => 'keep-alive, Upgrade',
        ];
        $request->server = [];

        self::assertTrue($adapter->isWebSocketUpgrade($request));
    }

    #[Test]
    public function rejectsNonWebSocketRequest(): void
    {
        $adapter = $this->createAdapter();

        $request = new \stdClass();
        $request->header = ['content-type' => 'application/json'];
        $request->server = [];

        self::assertFalse($adapter->isWebSocketUpgrade($request));
    }

    #[Test]
    public function rejectsUpgradeWithoutConnectionHeader(): void
    {
        $adapter = $this->createAdapter();

        $request = new \stdClass();
        $request->header = ['upgrade' => 'websocket'];
        $request->server = [];

        self::assertFalse($adapter->isWebSocketUpgrade($request));
    }

    #[Test]
    public function rejectsConnectionUpgradeWithoutUpgradeHeader(): void
    {
        $adapter = $this->createAdapter();

        $request = new \stdClass();
        $request->header = ['connection' => 'Upgrade'];
        $request->server = [];

        self::assertFalse($adapter->isWebSocketUpgrade($request));
    }

    #[Test]
    public function rejectsNonWebsocketUpgradeValue(): void
    {
        $adapter = $this->createAdapter();

        $request = new \stdClass();
        $request->header = ['upgrade' => 'h2c', 'connection' => 'Upgrade'];
        $request->server = [];

        self::assertFalse($adapter->isWebSocketUpgrade($request));
    }

    #[Test]
    public function delegatesHttpRequestToHttpAdapter(): void
    {
        $httpCalled = false;
        $httpAdapter = function (object $req, object $res) use (&$httpCalled): void {
            $httpCalled = true;
        };

        $adapter = new RealtimeServerAdapter(
            httpAdapter: $httpAdapter,
            wsHandler: $this->createMock(WebSocketHandler::class),
            logger: new NullLogger(),
        );

        $request = new \stdClass();
        $request->header = ['content-type' => 'text/html'];
        $request->server = [];
        $response = new \stdClass();

        $adapter($request, $response);

        self::assertTrue($httpCalled);
    }

    #[Test]
    public function delegatesWebSocketToHandler(): void
    {
        $openCalled = false;
        $wsHandler = new class ($openCalled) implements WebSocketHandler {
            public function __construct(private bool &$openCalled) {}
            public function onOpen(WebSocketContext $ctx): void { $this->openCalled = true; }
            public function onMessage(WebSocketContext $ctx, string $data): void {}
            public function onClose(WebSocketContext $ctx): void {}
        };

        $httpCalled = false;
        $httpAdapter = function () use (&$httpCalled): void { $httpCalled = true; };

        $adapter = new RealtimeServerAdapter(
            httpAdapter: $httpAdapter,
            wsHandler: $wsHandler,
            logger: new NullLogger(),
        );

        $request = new \stdClass();
        $request->header = ['upgrade' => 'websocket', 'connection' => 'Upgrade'];
        $request->server = [];
        $request->fd = 7;
        $response = new \stdClass();

        $adapter($request, $response);

        self::assertTrue($openCalled);
        self::assertFalse($httpCalled);
    }

    #[Test]
    public function delegatesHttpWhenNoWsHandler(): void
    {
        $httpCalled = false;
        $httpAdapter = function () use (&$httpCalled): void { $httpCalled = true; };

        $adapter = new RealtimeServerAdapter(
            httpAdapter: $httpAdapter,
            wsHandler: null,
            logger: new NullLogger(),
        );

        // Even with WS headers, no handler → delegate to HTTP
        $request = new \stdClass();
        $request->header = ['upgrade' => 'websocket', 'connection' => 'Upgrade'];
        $request->server = [];
        $response = new \stdClass();

        $adapter($request, $response);

        self::assertTrue($httpCalled);
    }

    #[Test]
    public function wsMaxLifetimeIsConfigurable(): void
    {
        $adapter = new RealtimeServerAdapter(
            httpAdapter: fn() => null,
            wsHandler: null,
            logger: new NullLogger(),
            wsMaxLifetimeSeconds: 7200,
        );

        self::assertSame(7200, $adapter->getWsMaxLifetimeSeconds());
    }

    #[Test]
    public function wsMaxLifetimeDefaultsTo3600(): void
    {
        $adapter = new RealtimeServerAdapter(
            httpAdapter: fn() => null,
            wsHandler: null,
            logger: new NullLogger(),
        );

        self::assertSame(3600, $adapter->getWsMaxLifetimeSeconds());
    }

    #[Test]
    public function metricsIncrementOnWebSocketOpen(): void
    {
        $metrics = new RealtimeMetrics();
        $wsHandler = new class implements WebSocketHandler {
            public function onOpen(WebSocketContext $ctx): void {}
            public function onMessage(WebSocketContext $ctx, string $data): void {}
            public function onClose(WebSocketContext $ctx): void {}
        };

        $adapter = new RealtimeServerAdapter(
            httpAdapter: fn() => null,
            wsHandler: $wsHandler,
            logger: new NullLogger(),
            metrics: $metrics,
        );

        $request = new \stdClass();
        $request->header = ['upgrade' => 'websocket', 'connection' => 'Upgrade'];
        $request->server = [];
        $request->fd = 1;
        $response = new \stdClass();

        self::assertSame(0, $metrics->getConnectionsActive());
        $adapter($request, $response);
        self::assertSame(1, $metrics->getConnectionsActive());
    }

    #[Test]
    public function metricsDecrementOnWebSocketClose(): void
    {
        $metrics = new RealtimeMetrics();
        $closeFnRef = null;

        $wsHandler = new class ($closeFnRef) implements WebSocketHandler {
            public function __construct(private &$ref) {}
            public function onOpen(WebSocketContext $ctx): void {
                $this->ref = fn() => $ctx->close();
            }
            public function onMessage(WebSocketContext $ctx, string $data): void {}
            public function onClose(WebSocketContext $ctx): void {}
        };

        $adapter = new RealtimeServerAdapter(
            httpAdapter: fn() => null,
            wsHandler: $wsHandler,
            logger: new NullLogger(),
            metrics: $metrics,
        );

        $request = new \stdClass();
        $request->header = ['upgrade' => 'websocket', 'connection' => 'Upgrade'];
        $request->server = [];
        $request->fd = 1;
        $response = new \stdClass();

        $adapter($request, $response);
        self::assertSame(1, $metrics->getConnectionsActive());

        ($closeFnRef)();
        self::assertSame(0, $metrics->getConnectionsActive());
    }

    #[Test]
    public function handlesEmptyHeaders(): void
    {
        $adapter = $this->createAdapter();

        $request = new \stdClass();
        $request->header = [];
        $request->server = [];

        self::assertFalse($adapter->isWebSocketUpgrade($request));
    }

    private function createAdapter(?WebSocketHandler $wsHandler = null): RealtimeServerAdapter
    {
        return new RealtimeServerAdapter(
            httpAdapter: fn() => null,
            wsHandler: $wsHandler ?? $this->createMock(WebSocketHandler::class),
            logger: new NullLogger(),
        );
    }
}
