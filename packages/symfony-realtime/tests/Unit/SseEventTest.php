<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyRealtime\Tests\Unit;

use AsyncPlatform\SymfonyRealtime\SseEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SseEventTest extends TestCase
{
    #[Test]
    public function formatsSimpleDataEvent(): void
    {
        $event = new SseEvent(data: 'hello');

        self::assertSame("data: hello\n\n", $event->format());
    }

    #[Test]
    public function formatsEventWithAllFields(): void
    {
        $event = new SseEvent(
            data: 'payload',
            event: 'message',
            id: '42',
            retry: 3000,
        );

        $expected = "event: message\ndata: payload\nid: 42\nretry: 3000\n\n";
        self::assertSame($expected, $event->format());
    }

    #[Test]
    public function formatsMultiLineData(): void
    {
        $event = new SseEvent(data: "line1\nline2\nline3");

        $expected = "data: line1\ndata: line2\ndata: line3\n\n";
        self::assertSame($expected, $event->format());
    }

    #[Test]
    public function endsWithDoubleNewline(): void
    {
        $event = new SseEvent(data: 'test');
        $formatted = $event->format();

        self::assertTrue(str_ends_with($formatted, "\n\n"));
    }

    #[Test]
    public function parsesSimpleDataEvent(): void
    {
        $parsed = SseEvent::parse("data: hello\n\n");

        self::assertSame('hello', $parsed->data);
        self::assertNull($parsed->event);
        self::assertNull($parsed->id);
        self::assertNull($parsed->retry);
    }

    #[Test]
    public function parsesEventWithAllFields(): void
    {
        $raw = "event: update\ndata: payload\nid: 99\nretry: 5000\n\n";
        $parsed = SseEvent::parse($raw);

        self::assertSame('payload', $parsed->data);
        self::assertSame('update', $parsed->event);
        self::assertSame('99', $parsed->id);
        self::assertSame(5000, $parsed->retry);
    }

    #[Test]
    public function parsesMultiLineData(): void
    {
        $raw = "data: line1\ndata: line2\ndata: line3\n\n";
        $parsed = SseEvent::parse($raw);

        self::assertSame("line1\nline2\nline3", $parsed->data);
    }

    #[Test]
    public function roundTripPreservesAllFields(): void
    {
        $original = new SseEvent(
            data: "multi\nline\ndata",
            event: 'custom',
            id: 'evt-123',
            retry: 1500,
        );

        $parsed = SseEvent::parse($original->format());

        self::assertSame($original->data, $parsed->data);
        self::assertSame($original->event, $parsed->event);
        self::assertSame($original->id, $parsed->id);
        self::assertSame($original->retry, $parsed->retry);
    }

    #[Test]
    public function formatsEmptyData(): void
    {
        $event = new SseEvent(data: '');

        self::assertSame("data: \n\n", $event->format());
    }
}
