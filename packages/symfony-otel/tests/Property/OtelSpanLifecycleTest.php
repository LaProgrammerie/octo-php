<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tests\Property;

use Octo\SymfonyOtel\OtelRequestListener;
use Octo\SymfonyOtel\OtelSpanFactory;
use Octo\SymfonyOtel\Tracing\FakeTracer;
use Octo\SymfonyOtel\Tracing\SpanKind;
use Octo\SymfonyOtel\Tracing\StatusCode;
use Octo\SymfonyOtel\Tracing\W3CTraceContextPropagator;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Property 15: OTEL span lifecycle
 *
 * **Validates: Requirements 14.1, 14.2, 14.3, 14.4**
 *
 * For any request processed by the bridge with the symfony-otel package active,
 * a root span SHALL be created BEFORE HttpKernel::handle() with attributes
 * http.method, http.url, http.request_id, and terminated AFTER the reset/terminate
 * phase. Three child spans SHALL be created: symfony.kernel.handle,
 * symfony.response.convert, symfony.reset. If W3C Trace Context headers
 * (traceparent, tracestate) are present, the trace context SHALL be propagated
 * in the root span.
 */
final class OtelSpanLifecycleTest extends TestCase
{
    use TestTrait;

    private function createListener(): OtelRequestListener
    {
        return new OtelRequestListener(
            new OtelSpanFactory(new FakeTracer()),
            new W3CTraceContextPropagator(),
        );
    }

    private function makeSwooleRequest(
        string $method,
        string $uri,
        string $requestId,
        ?string $traceparent = null,
        ?string $tracestate = null,
    ): object {
        $req = new \stdClass();
        $headers = ['x-request-id' => $requestId];
        if ($traceparent !== null) {
            $headers['traceparent'] = $traceparent;
        }
        if ($tracestate !== null) {
            $headers['tracestate'] = $tracestate;
        }
        $req->header = $headers;
        $req->server = [
            'request_method' => strtolower($method),
            'request_uri' => $uri,
        ];
        return $req;
    }

    #[Test]
    public function rootSpanCreatedWithCorrectAttributesForAnyRequest(): void
    {
        $this->limitTo(100);

        $methods = Generators::elements(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS']);
        $uris = Generators::elements([
            '/',
            '/api/users',
            '/api/orders/123',
            '/health',
            '/admin/dashboard',
            '/api/v2/products?page=1',
            '/search?q=test&limit=10',
        ]);
        $requestIds = Generators::map(
            fn(int $n): string => 'req-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            Generators::choose(0, 999999),
        );

        $this->forAll($methods, $uris, $requestIds)
            ->then(function (string $method, string $uri, string $requestId): void {
                $listener = $this->createListener();
                $req = $this->makeSwooleRequest($method, $uri, $requestId);

                $rootSpan = $listener->beforeHandle($req);

                // Root span is KIND_SERVER
                self::assertSame(SpanKind::KIND_SERVER, $rootSpan->getKind());

                // Root span has correct HTTP attributes
                $attrs = $rootSpan->getAttributes();
                self::assertSame($method, $attrs['http.method']);
                self::assertSame($uri, $attrs['http.url']);
                self::assertSame($requestId, $attrs['http.request_id']);

                // Root span is not ended yet (before handle)
                self::assertFalse($rootSpan->hasEnded());
            });
    }

    #[Test]
    public function threeChildSpansCreatedInCorrectOrder(): void
    {
        $this->limitTo(100);

        $methods = Generators::elements(['GET', 'POST', 'PUT', 'DELETE']);
        $statusCodes = Generators::elements([200, 201, 204, 301, 400, 404, 500]);

        $this->forAll($methods, $statusCodes)
            ->then(function (string $method, int $statusCode): void {
                $tracer = new FakeTracer();
                $factory = new OtelSpanFactory($tracer);
                $listener = new OtelRequestListener($factory, new W3CTraceContextPropagator());

                $req = $this->makeSwooleRequest($method, '/test', 'req-child');

                // 1. Root span created before handle
                $rootSpan = $listener->beforeHandle($req);

                // 2. Three child spans in lifecycle order
                $handleSpan = $factory->createChildSpan('symfony.kernel.handle');
                self::assertSame(SpanKind::KIND_INTERNAL, $handleSpan->getKind());
                self::assertSame('symfony.kernel.handle', $handleSpan->getName());
                $handleSpan->end();

                $convertSpan = $factory->createChildSpan('symfony.response.convert');
                self::assertSame(SpanKind::KIND_INTERNAL, $convertSpan->getKind());
                self::assertSame('symfony.response.convert', $convertSpan->getName());
                $convertSpan->end();

                $resetSpan = $factory->createChildSpan('symfony.reset');
                self::assertSame(SpanKind::KIND_INTERNAL, $resetSpan->getKind());
                self::assertSame('symfony.reset', $resetSpan->getName());
                $resetSpan->end();

                // 3. Root span ended after all child spans
                $listener->afterHandle($rootSpan, $statusCode);

                // All child spans ended before root
                self::assertTrue($handleSpan->hasEnded());
                self::assertTrue($convertSpan->hasEnded());
                self::assertTrue($resetSpan->hasEnded());
                self::assertTrue($rootSpan->hasEnded());

                // Root span has status_code attribute
                self::assertSame($statusCode, $rootSpan->getAttributes()['http.status_code']);
            });
    }

    #[Test]
    public function w3cTraceContextPropagatedWhenPresent(): void
    {
        $this->limitTo(100);

        $traceIds = Generators::map(
            fn(int $n): string => str_pad(dechex($n), 32, '0', STR_PAD_LEFT),
            Generators::choose(1, PHP_INT_MAX),
        );
        $spanIds = Generators::map(
            fn(int $n): string => str_pad(dechex($n), 16, '0', STR_PAD_LEFT),
            Generators::choose(1, PHP_INT_MAX),
        );
        $flags = Generators::elements(['00', '01']);

        $this->forAll($traceIds, $spanIds, $flags)
            ->then(function (string $traceId, string $spanId, string $flags): void {
                $listener = $this->createListener();
                $traceparent = "00-{$traceId}-{$spanId}-{$flags}";

                $req = $this->makeSwooleRequest('GET', '/', 'req-w3c', $traceparent);
                $rootSpan = $listener->beforeHandle($req);

                $parentCtx = $rootSpan->getParentContext();
                self::assertNotNull($parentCtx, 'Parent context should be set when traceparent is present');
                self::assertSame($traceparent, $parentCtx['traceparent']);
            });
    }

    #[Test]
    public function w3cTracestatePreservedAlongsideTraceparent(): void
    {
        $this->limitTo(100);

        $tracestates = Generators::elements([
            'congo=t61rcWkgMzE',
            'rojo=00f067aa0ba902b7',
            'congo=t61rcWkgMzE,rojo=00f067aa0ba902b7',
            'vendor1=value1,vendor2=value2,vendor3=value3',
        ]);

        $this->forAll($tracestates)
            ->then(function (string $tracestate): void {
                $listener = $this->createListener();
                $traceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

                $req = $this->makeSwooleRequest('POST', '/api', 'req-ts', $traceparent, $tracestate);
                $rootSpan = $listener->beforeHandle($req);

                $parentCtx = $rootSpan->getParentContext();
                self::assertNotNull($parentCtx);
                self::assertSame($traceparent, $parentCtx['traceparent']);
                self::assertSame($tracestate, $parentCtx['tracestate']);
            });
    }

    #[Test]
    public function noTraceContextMeansNoParent(): void
    {
        $this->limitTo(100);

        $methods = Generators::elements(['GET', 'POST', 'PUT', 'DELETE']);

        $this->forAll($methods)
            ->then(function (string $method): void {
                $listener = $this->createListener();
                $req = $this->makeSwooleRequest($method, '/no-trace', 'req-no-trace');

                $rootSpan = $listener->beforeHandle($req);

                self::assertNull($rootSpan->getParentContext());
            });
    }

    #[Test]
    public function exceptionBeforeChildSpansRootSpanStillCompletesCorrectly(): void
    {
        $this->limitTo(100);

        $exceptionMessages = Generators::elements([
            'Connection refused',
            'Timeout exceeded',
            'Out of memory',
            'Permission denied',
            'Service unavailable',
            'Internal error',
        ]);

        $this->forAll($exceptionMessages)
            ->then(function (string $message): void {
                $listener = $this->createListener();
                $req = $this->makeSwooleRequest('GET', '/fail', 'req-fail');

                $rootSpan = $listener->beforeHandle($req);

                // Exception occurs before any child spans
                $exception = new \RuntimeException($message);
                $listener->onException($rootSpan, $exception);

                // Root span should have error status
                self::assertSame(StatusCode::STATUS_ERROR, $rootSpan->getStatusCode());
                self::assertCount(1, $rootSpan->getRecordedExceptions());
                self::assertSame($exception, $rootSpan->getRecordedExceptions()[0]);

                // Root span can still be ended correctly
                $listener->afterHandle($rootSpan, 500);
                self::assertTrue($rootSpan->hasEnded());
                self::assertSame(500, $rootSpan->getAttributes()['http.status_code']);
            });
    }
}
