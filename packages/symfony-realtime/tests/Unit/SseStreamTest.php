<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyRealtime\Tests\Unit;

use AsyncPlatform\SymfonyRealtime\SseEvent;
use AsyncPlatform\SymfonyRealtime\SseStream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SseStreamTest extends TestCase
{
    #[Test]
    public function sendWritesFormattedEventToFacade(): void
    {
        $written = [];
        $stream = new SseStream(writeFn: $this->captureFn($written));
        $stream->send(new SseEvent(data: 'hello', event: 'msg'));

        self::assertCount(1, $written);
        self::assertSame("event: msg\ndata: hello\n\n", $written[0]);
    }

    #[Test]
    public function sendKeepAliveWritesComment(): void
    {
        $written = [];
        $stream = new SseStream(writeFn: $this->captureFn($written));
        $stream->sendKeepAlive();

        self::assertCount(1, $written);
        self::assertSame(": keep-alive\n\n", $written[0]);
    }

    #[Test]
    public function shouldSendKeepAliveReturnsFalseImmediately(): void
    {
        $stream = new SseStream(
            writeFn: fn(string $s) => true,
            keepAliveSeconds: 15,
        );

        self::assertFalse($stream->shouldSendKeepAlive());
    }

    #[Test]
    public function shouldSendKeepAliveReturnsTrueAfterInterval(): void
    {
        // Use 0 seconds so it's immediately due
        $stream = new SseStream(
            writeFn: fn(string $s) => true,
            keepAliveSeconds: 0,
        );

        self::assertTrue($stream->shouldSendKeepAlive());
    }

    #[Test]
    public function sendResetsKeepAliveTimer(): void
    {
        $stream = new SseStream(
            writeFn: fn(string $s) => true,
            keepAliveSeconds: 9999,
        );

        $stream->send(new SseEvent(data: 'x'));
        self::assertFalse($stream->shouldSendKeepAlive());
    }

    #[Test]
    public function tracksLastEventId(): void
    {
        $stream = new SseStream(writeFn: fn(string $s) => true);
        self::assertNull($stream->getLastEventId());

        $stream->send(new SseEvent(data: 'a', id: 'evt-1'));
        self::assertSame('evt-1', $stream->getLastEventId());

        $stream->send(new SseEvent(data: 'b', id: 'evt-2'));
        self::assertSame('evt-2', $stream->getLastEventId());

        // Event without id doesn't change lastEventId
        $stream->send(new SseEvent(data: 'c'));
        self::assertSame('evt-2', $stream->getLastEventId());
    }

    #[Test]
    public function supportsLastEventIdFromConstructor(): void
    {
        $stream = new SseStream(
            writeFn: fn(string $s) => true,
            lastEventId: 'reconnect-42',
        );

        self::assertSame('reconnect-42', $stream->getLastEventId());
    }

    /**
     * Creates a write callable that captures all written content.
     *
     * @param list<string> $written
     * @return callable(string): bool
     */
    private function captureFn(array &$written): callable
    {
        return function (string $content) use (&$written): bool {
            $written[] = $content;
            return true;
        };
    }
}
