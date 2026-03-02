<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyRealtime\Tests\Property;

use AsyncPlatform\SymfonyRealtime\RealtimeServerAdapter;
use AsyncPlatform\SymfonyRealtime\WebSocketContext;
use AsyncPlatform\SymfonyRealtime\WebSocketHandler;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Property 12: WebSocket upgrade detection
 *
 * **Validates: Requirements 12.3**
 *
 * Requests with both Upgrade: websocket AND Connection: Upgrade headers
 * (case-insensitive) are routed to the WebSocket handler.
 * All other requests are routed to the HTTP adapter.
 */
final class WebSocketUpgradeDetectionTest extends TestCase
{
    use TestTrait;

    #[Test]
    public function requestsWithBothWsHeadersAreDetectedAsUpgrade(): void
    {
        $this->limitTo(100);

        $upgradeCases = Generators::elements([
            'websocket', 'WebSocket', 'WEBSOCKET', 'Websocket',
        ]);
        $connectionCases = Generators::elements([
            'Upgrade', 'upgrade', 'UPGRADE',
            'keep-alive, Upgrade', 'Upgrade, keep-alive',
            'keep-alive, upgrade', 'KEEP-ALIVE, UPGRADE',
        ]);

        $this->forAll($upgradeCases, $connectionCases)
            ->then(function (string $upgradeVal, string $connectionVal): void {
                $adapter = $this->createAdapter();

                $request = new \stdClass();
                $request->header = [
                    'upgrade' => $upgradeVal,
                    'connection' => $connectionVal,
                ];
                $request->server = [];

                self::assertTrue(
                    $adapter->isWebSocketUpgrade($request),
                    "Expected WS upgrade for Upgrade: {$upgradeVal}, Connection: {$connectionVal}",
                );
            });
    }

    #[Test]
    public function requestsWithoutUpgradeHeaderAreNotDetected(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::string(),
            Generators::elements(['keep-alive', 'close', '', 'Upgrade', 'upgrade']),
        )->then(function (string $randomHeader, string $connectionVal): void {
            $adapter = $this->createAdapter();

            // No 'upgrade' header at all
            $request = new \stdClass();
            $request->header = [
                'connection' => $connectionVal,
                'x-custom' => $randomHeader,
            ];
            $request->server = [];

            self::assertFalse(
                $adapter->isWebSocketUpgrade($request),
                'Request without Upgrade header should not be detected as WS upgrade',
            );
        });
    }

    #[Test]
    public function requestsWithoutConnectionHeaderAreNotDetected(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::elements(['websocket', 'WebSocket', 'WEBSOCKET']),
            Generators::string(),
        )->then(function (string $upgradeVal, string $randomHeader): void {
            $adapter = $this->createAdapter();

            // No 'connection' header at all
            $request = new \stdClass();
            $request->header = [
                'upgrade' => $upgradeVal,
                'x-custom' => $randomHeader,
            ];
            $request->server = [];

            self::assertFalse(
                $adapter->isWebSocketUpgrade($request),
                'Request without Connection header should not be detected as WS upgrade',
            );
        });
    }

    #[Test]
    public function nonWebsocketUpgradeValuesAreNotDetected(): void
    {
        $this->limitTo(100);

        $nonWsUpgrades = Generators::elements([
            'h2c', 'HTTP/2.0', 'TLS/1.0', 'IRC/6.9', '', 'web-socket', 'ws',
        ]);

        $this->forAll($nonWsUpgrades)->then(function (string $upgradeVal): void {
            $adapter = $this->createAdapter();

            $request = new \stdClass();
            $request->header = [
                'upgrade' => $upgradeVal,
                'connection' => 'Upgrade',
            ];
            $request->server = [];

            self::assertFalse(
                $adapter->isWebSocketUpgrade($request),
                "Upgrade: {$upgradeVal} should not be detected as WS upgrade",
            );
        });
    }

    #[Test]
    public function routingMatchesDetection(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::elements([true, false]),
        )->then(function (bool $isWs): void {
            $httpCalled = false;
            $wsCalled = false;

            $httpAdapter = function () use (&$httpCalled): void { $httpCalled = true; };
            $wsHandler = new class ($wsCalled) implements WebSocketHandler {
                public function __construct(private bool &$called) {}
                public function onOpen(WebSocketContext $ctx): void { $this->called = true; }
                public function onMessage(WebSocketContext $ctx, string $data): void {}
                public function onClose(WebSocketContext $ctx): void {}
            };

            $adapter = new RealtimeServerAdapter(
                httpAdapter: $httpAdapter,
                wsHandler: $wsHandler,
                logger: new NullLogger(),
            );

            $request = new \stdClass();
            $request->server = [];
            $request->fd = 1;

            if ($isWs) {
                $request->header = ['upgrade' => 'websocket', 'connection' => 'Upgrade'];
            } else {
                $request->header = ['content-type' => 'text/html'];
            }

            $response = new \stdClass();
            $adapter($request, $response);

            if ($isWs) {
                self::assertTrue($wsCalled, 'WS handler should be called for WS upgrade');
                self::assertFalse($httpCalled, 'HTTP adapter should NOT be called for WS upgrade');
            } else {
                self::assertTrue($httpCalled, 'HTTP adapter should be called for non-WS request');
                self::assertFalse($wsCalled, 'WS handler should NOT be called for non-WS request');
            }
        });
    }

    private function createAdapter(): RealtimeServerAdapter
    {
        return new RealtimeServerAdapter(
            httpAdapter: fn() => null,
            wsHandler: $this->createMock(WebSocketHandler::class),
            logger: new NullLogger(),
        );
    }
}
