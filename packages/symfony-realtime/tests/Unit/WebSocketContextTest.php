<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyRealtime\Tests\Unit;

use AsyncPlatform\SymfonyRealtime\WebSocketContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebSocketContextTest extends TestCase
{
    #[Test]
    public function exposesDtoFields(): void
    {
        $ctx = new WebSocketContext(
            connectionId: 42,
            requestId: 'req-abc',
            headers: ['host' => 'example.com', 'x-custom' => 'val'],
            sendFn: static function (string $data): void {},
            closeFn: static function (): void {},
        );

        self::assertSame(42, $ctx->connectionId);
        self::assertSame('req-abc', $ctx->requestId);
        self::assertSame(['host' => 'example.com', 'x-custom' => 'val'], $ctx->headers);
    }

    #[Test]
    public function sendDelegatesToClosure(): void
    {
        $sent = [];
        $ctx = new WebSocketContext(
            connectionId: 1,
            requestId: 'r1',
            headers: [],
            sendFn: static function (string $data) use (&$sent): void {
                $sent[] = $data;
            },
            closeFn: static function (): void {},
        );

        $ctx->send('hello');
        $ctx->send('world');

        self::assertSame(['hello', 'world'], $sent);
    }

    #[Test]
    public function closeDelegatesToClosure(): void
    {
        $closed = false;
        $ctx = new WebSocketContext(
            connectionId: 1,
            requestId: 'r1',
            headers: [],
            sendFn: static function (string $data): void {},
            closeFn: static function () use (&$closed): void {
                $closed = true;
            },
        );

        self::assertFalse($closed);
        $ctx->close();
        self::assertTrue($closed);
    }
}
