<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyOtel\Tests\Unit;

use AsyncPlatform\SymfonyOtel\OtelRequestListener;
use AsyncPlatform\SymfonyOtel\OtelSpanFactory;
use AsyncPlatform\SymfonyOtel\Tracing\FakeTracer;
use AsyncPlatform\SymfonyOtel\Tracing\SpanKind;
use AsyncPlatform\SymfonyOtel\Tracing\StatusCode;
use AsyncPlatform\SymfonyOtel\Tracing\W3CTraceContextPropagator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OtelRequestListenerTest extends TestCase
{
    private function createListener(): OtelRequestListener
    {
        $tracer = new FakeTracer();
        $factory = new OtelSpanFactory($tracer);
        $propagator = new W3CTraceContextPropagator();

        return new OtelRequestListener($factory, $propagator);
    }

    private function makeSwooleRequest(array $headers = [], array $server = []): object
    {
        $req = new \stdClass();
        $req->header = $headers;
        $req->server = array_merge([
            'request_method' => 'GET',
            'request_uri' => '/',
        ], $server);
        return $req;
    }

    #[Test]
    public function beforeHandleCreatesRootSpanWithHttpAttributes(): void
    {
        $listener = $this->createListener();
        $req = $this->makeSwooleRequest(
            headers: ['x-request-id' => 'req-abc'],
            server: ['request_method' => 'POST', 'request_uri' => '/api/users'],
        );

        $span = $listener->beforeHandle($req);

        self::assertSame(SpanKind::KIND_SERVER, $span->getKind());
        self::assertSame('HTTP POST /api/users', $span->getName());

        $attrs = $span->getAttributes();
        self::assertSame('POST', $attrs['http.method']);
        self::assertSame('/api/users', $attrs['http.url']);
        self::assertSame('req-abc', $attrs['http.request_id']);
        self::assertFalse($span->hasEnded());
    }

    #[Test]
    public function beforeHandleExtractsTraceparent(): void
    {
        $listener = $this->createListener();
        $traceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $req = $this->makeSwooleRequest(
            headers: [
                'x-request-id' => 'req-trace',
                'traceparent' => $traceparent,
            ],
        );

        $span = $listener->beforeHandle($req);

        $parentCtx = $span->getParentContext();
        self::assertNotNull($parentCtx);
        self::assertSame($traceparent, $parentCtx['traceparent']);
    }

    #[Test]
    public function beforeHandleExtractsTraceparentAndTracestate(): void
    {
        $listener = $this->createListener();
        $traceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $tracestate = 'congo=t61rcWkgMzE,rojo=00f067aa0ba902b7';
        $req = $this->makeSwooleRequest(
            headers: [
                'x-request-id' => 'req-ts',
                'traceparent' => $traceparent,
                'tracestate' => $tracestate,
            ],
        );

        $span = $listener->beforeHandle($req);

        $parentCtx = $span->getParentContext();
        self::assertNotNull($parentCtx);
        self::assertSame($traceparent, $parentCtx['traceparent']);
        self::assertSame($tracestate, $parentCtx['tracestate']);
    }

    #[Test]
    public function beforeHandleWithoutTraceContextHasNoParent(): void
    {
        $listener = $this->createListener();
        $req = $this->makeSwooleRequest(headers: ['x-request-id' => 'req-no-trace']);

        $span = $listener->beforeHandle($req);

        self::assertNull($span->getParentContext());
    }

    #[Test]
    public function beforeHandleWithMissingRequestIdUsesUnknown(): void
    {
        $listener = $this->createListener();
        $req = $this->makeSwooleRequest();

        $span = $listener->beforeHandle($req);

        self::assertSame('unknown', $span->getAttributes()['http.request_id']);
    }

    #[Test]
    public function afterHandleSetsStatusCodeAndEndsSpan(): void
    {
        $listener = $this->createListener();
        $req = $this->makeSwooleRequest(headers: ['x-request-id' => 'req-1']);
        $span = $listener->beforeHandle($req);

        $listener->afterHandle($span, 200);

        self::assertTrue($span->hasEnded());
        self::assertSame(200, $span->getAttributes()['http.status_code']);
    }

    #[Test]
    public function afterHandleSetsRouteAndController(): void
    {
        $listener = $this->createListener();
        $req = $this->makeSwooleRequest(headers: ['x-request-id' => 'req-2']);
        $span = $listener->beforeHandle($req);

        $listener->afterHandle($span, 200, 'app_user_list', 'App\\Controller\\UserController::list');

        $attrs = $span->getAttributes();
        self::assertSame('app_user_list', $attrs['symfony.route']);
        self::assertSame('App\\Controller\\UserController::list', $attrs['symfony.controller']);
    }

    #[Test]
    public function afterHandleWithoutRouteAndControllerOmitsThem(): void
    {
        $listener = $this->createListener();
        $req = $this->makeSwooleRequest(headers: ['x-request-id' => 'req-3']);
        $span = $listener->beforeHandle($req);

        $listener->afterHandle($span, 404);

        $attrs = $span->getAttributes();
        self::assertArrayNotHasKey('symfony.route', $attrs);
        self::assertArrayNotHasKey('symfony.controller', $attrs);
    }

    #[Test]
    public function onExceptionRecordsExceptionAndSetsErrorStatus(): void
    {
        $listener = $this->createListener();
        $req = $this->makeSwooleRequest(headers: ['x-request-id' => 'req-err']);
        $span = $listener->beforeHandle($req);

        $exception = new \RuntimeException('Something went wrong');
        $listener->onException($span, $exception);

        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatusCode());
        $recorded = $span->getRecordedExceptions();
        self::assertCount(1, $recorded);
        self::assertSame($exception, $recorded[0]);
    }

    #[Test]
    public function exceptionBeforeChildSpansRootSpanStillEndsCorrectly(): void
    {
        $listener = $this->createListener();
        $req = $this->makeSwooleRequest(headers: ['x-request-id' => 'req-early-err']);
        $span = $listener->beforeHandle($req);

        // Exception occurs before any child spans are created
        $exception = new \LogicException('Early failure');
        $listener->onException($span, $exception);

        // Root span can still be ended via afterHandle
        $listener->afterHandle($span, 500);

        self::assertTrue($span->hasEnded());
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatusCode());
        self::assertSame(500, $span->getAttributes()['http.status_code']);
        self::assertCount(1, $span->getRecordedExceptions());
    }
}
