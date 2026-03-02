<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyOtel\Tests\Unit;

use AsyncPlatform\SymfonyOtel\Tracing\W3CTraceContextPropagator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class W3CTraceContextPropagatorTest extends TestCase
{
    #[Test]
    public function extractTraceparentAndTracestate(): void
    {
        $propagator = new W3CTraceContextPropagator();

        $result = $propagator->extract([
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            'tracestate' => 'congo=t61rcWkgMzE',
        ]);

        self::assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', $result['traceparent']);
        self::assertSame('congo=t61rcWkgMzE', $result['tracestate']);
    }

    #[Test]
    public function extractTraceparentOnly(): void
    {
        $propagator = new W3CTraceContextPropagator();

        $result = $propagator->extract([
            'traceparent' => '00-abcdef1234567890abcdef1234567890-1234567890abcdef-00',
        ]);

        self::assertSame('00-abcdef1234567890abcdef1234567890-1234567890abcdef-00', $result['traceparent']);
        self::assertArrayNotHasKey('tracestate', $result);
    }

    #[Test]
    public function extractEmptyHeadersReturnsEmptyArray(): void
    {
        $propagator = new W3CTraceContextPropagator();

        $result = $propagator->extract([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function extractIgnoresEmptyValues(): void
    {
        $propagator = new W3CTraceContextPropagator();

        $result = $propagator->extract([
            'traceparent' => '',
            'tracestate' => '',
        ]);

        self::assertSame([], $result);
    }

    #[Test]
    public function extractIgnoresUnrelatedHeaders(): void
    {
        $propagator = new W3CTraceContextPropagator();

        $result = $propagator->extract([
            'content-type' => 'application/json',
            'x-request-id' => 'req-123',
        ]);

        self::assertSame([], $result);
    }
}
