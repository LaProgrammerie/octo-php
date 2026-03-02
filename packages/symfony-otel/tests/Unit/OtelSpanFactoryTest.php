<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyOtel\Tests\Unit;

use AsyncPlatform\SymfonyOtel\OtelSpanFactory;
use AsyncPlatform\SymfonyOtel\Tracing\FakeTracer;
use AsyncPlatform\SymfonyOtel\Tracing\SpanKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OtelSpanFactoryTest extends TestCase
{
    #[Test]
    public function createRootSpanSetsServerKindAndHttpAttributes(): void
    {
        $tracer = new FakeTracer();
        $factory = new OtelSpanFactory($tracer);

        $span = $factory->createRootSpan('req-123', 'GET', '/api/users');

        self::assertSame(SpanKind::KIND_SERVER, $span->getKind());
        self::assertSame('HTTP GET /api/users', $span->getName());

        $attrs = $span->getAttributes();
        self::assertSame('GET', $attrs['http.method']);
        self::assertSame('/api/users', $attrs['http.url']);
        self::assertSame('req-123', $attrs['http.request_id']);
    }

    #[Test]
    public function createRootSpanWithParentContext(): void
    {
        $tracer = new FakeTracer();
        $factory = new OtelSpanFactory($tracer);

        $parentCtx = [
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            'tracestate' => 'congo=t61rcWkgMzE',
        ];

        $span = $factory->createRootSpan('req-456', 'POST', '/api/orders', $parentCtx);

        self::assertSame($parentCtx, $span->getParentContext());
        self::assertSame(SpanKind::KIND_SERVER, $span->getKind());
    }

    #[Test]
    public function createRootSpanWithoutParentContext(): void
    {
        $tracer = new FakeTracer();
        $factory = new OtelSpanFactory($tracer);

        $span = $factory->createRootSpan('req-789', 'DELETE', '/api/items/1');

        self::assertNull($span->getParentContext());
    }

    #[Test]
    public function createRootSpanWithEmptyParentContextTreatedAsNull(): void
    {
        $tracer = new FakeTracer();
        $factory = new OtelSpanFactory($tracer);

        $span = $factory->createRootSpan('req-000', 'GET', '/', []);

        self::assertNull($span->getParentContext());
    }

    #[Test]
    public function createChildSpanSetsInternalKind(): void
    {
        $tracer = new FakeTracer();
        $factory = new OtelSpanFactory($tracer);

        $span = $factory->createChildSpan('symfony.kernel.handle');

        self::assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
        self::assertSame('symfony.kernel.handle', $span->getName());
    }

    #[Test]
    public function createChildSpansForAllLifecyclePhases(): void
    {
        $tracer = new FakeTracer();
        $factory = new OtelSpanFactory($tracer);

        $phases = ['symfony.kernel.handle', 'symfony.response.convert', 'symfony.reset'];

        foreach ($phases as $phase) {
            $span = $factory->createChildSpan($phase);
            self::assertSame($phase, $span->getName());
            self::assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
            self::assertFalse($span->hasEnded());
        }
    }
}
