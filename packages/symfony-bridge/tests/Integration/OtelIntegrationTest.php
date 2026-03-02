<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge\Tests\Integration;

require_once __DIR__ . '/IntegrationTestDoubles.php';

use AsyncPlatform\SymfonyOtel\OtelRequestListener;
use AsyncPlatform\SymfonyOtel\OtelSpanFactory;
use AsyncPlatform\SymfonyOtel\Tracing\FakeSpan;
use AsyncPlatform\SymfonyOtel\Tracing\FakeTracer;
use AsyncPlatform\SymfonyOtel\Tracing\SpanKind;
use AsyncPlatform\SymfonyOtel\Tracing\StatusCode;
use AsyncPlatform\SymfonyOtel\Tracing\W3CTraceContextPropagator;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: OTEL span lifecycle.
 *
 * Verifies:
 * - Spans created with correct attributes
 * - Full lifecycle: beforeHandle → child spans → afterHandle
 * - W3C trace context propagation
 * - Exception capture in root span
 * - Absence of the package → no error (tested via bundle auto-detection in BundleIntegrationTest)
 *
 * Requirements: 16.17, 16.18
 */
final class OtelIntegrationTest extends TestCase
{
    private FakeTracer $tracer;
    private OtelSpanFactory $spanFactory;
    private OtelRequestListener $listener;

    protected function setUp(): void
    {
        $this->tracer = new FakeTracer();
        $this->spanFactory = new OtelSpanFactory($this->tracer);
        $this->listener = new OtelRequestListener(
            $this->spanFactory,
            new W3CTraceContextPropagator(),
        );
    }

    public function testFullLifecycleBeforeHandleChildSpansAfterHandle(): void
    {
        $request = new IntegrationFakeSwooleRequest('GET', '/api/users', [
            'x-request-id' => 'otel-test-1',
        ]);

        // 1. beforeHandle — creates root span
        $rootSpan = $this->listener->beforeHandle($request);

        $this->assertInstanceOf(FakeSpan::class, $rootSpan);
        $this->assertSame(SpanKind::KIND_SERVER, $rootSpan->getKind());

        $attrs = $rootSpan->getAttributes();
        $this->assertSame('GET', $attrs['http.method']);
        $this->assertSame('/api/users', $attrs['http.url']);
        $this->assertSame('otel-test-1', $attrs['http.request_id']);

        // 2. Create child spans (simulating bridge lifecycle)
        $handleSpan = $this->spanFactory->createChildSpan('symfony.kernel.handle');
        $this->assertSame(SpanKind::KIND_INTERNAL, $handleSpan->getKind());
        $handleSpan->end();

        $convertSpan = $this->spanFactory->createChildSpan('symfony.response.convert');
        $convertSpan->end();

        $resetSpan = $this->spanFactory->createChildSpan('symfony.reset');
        $resetSpan->end();

        // 3. afterHandle — enrich and end root span
        $this->listener->afterHandle($rootSpan, 200, 'app_users_list', 'App\\Controller\\UserController::list');

        $this->assertTrue($rootSpan->hasEnded());
        $finalAttrs = $rootSpan->getAttributes();
        $this->assertSame(200, $finalAttrs['http.status_code']);
        $this->assertSame('app_users_list', $finalAttrs['symfony.route']);
        $this->assertSame('App\\Controller\\UserController::list', $finalAttrs['symfony.controller']);
    }

    public function testW3CTraceContextPropagation(): void
    {
        $traceParent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $traceState = 'congo=t61rcWkgMzE';

        $request = new IntegrationFakeSwooleRequest('POST', '/api/orders', [
            'x-request-id' => 'otel-w3c-1',
            'traceparent' => $traceParent,
            'tracestate' => $traceState,
        ]);

        $rootSpan = $this->listener->beforeHandle($request);

        $this->assertInstanceOf(FakeSpan::class, $rootSpan);
        $parentCtx = $rootSpan->getParentContext();
        $this->assertNotNull($parentCtx);
        $this->assertSame($traceParent, $parentCtx['traceparent']);
        $this->assertSame($traceState, $parentCtx['tracestate']);
    }

    public function testExceptionCapturedInRootSpan(): void
    {
        $request = new IntegrationFakeSwooleRequest('GET', '/api/fail', [
            'x-request-id' => 'otel-err-1',
        ]);

        $rootSpan = $this->listener->beforeHandle($request);

        // Simulate exception during handle
        $exception = new \RuntimeException('Something went wrong');
        $this->listener->onException($rootSpan, $exception);

        $this->assertCount(1, $rootSpan->getRecordedExceptions());
        $this->assertSame($exception, $rootSpan->getRecordedExceptions()[0]);
        $this->assertSame(StatusCode::STATUS_ERROR, $rootSpan->getStatusCode());
        $this->assertSame('Something went wrong', $rootSpan->getStatusDescription());

        // afterHandle still works after exception
        $this->listener->afterHandle($rootSpan, 500);
        $this->assertTrue($rootSpan->hasEnded());
        $this->assertSame(500, $rootSpan->getAttributes()['http.status_code']);
    }

    public function testExceptionBeforeChildSpansStillEndsRootSpan(): void
    {
        $request = new IntegrationFakeSwooleRequest('GET', '/api/early-fail', [
            'x-request-id' => 'otel-early-1',
        ]);

        $rootSpan = $this->listener->beforeHandle($request);

        // Exception happens immediately — no child spans created
        $exception = new \LogicException('Early failure');
        $this->listener->onException($rootSpan, $exception);
        $this->listener->afterHandle($rootSpan, 500);

        $this->assertTrue($rootSpan->hasEnded());
        $this->assertSame(StatusCode::STATUS_ERROR, $rootSpan->getStatusCode());
    }

    public function testNoTraceContextHeadersProducesNoParent(): void
    {
        $request = new IntegrationFakeSwooleRequest('GET', '/api/no-trace', [
            'x-request-id' => 'otel-notrace-1',
        ]);

        $rootSpan = $this->listener->beforeHandle($request);

        $this->assertNull($rootSpan->getParentContext());
    }

    public function testChildSpanAttributes(): void
    {
        $handleSpan = $this->spanFactory->createChildSpan('symfony.kernel.handle');
        $this->assertSame('symfony.kernel.handle', $handleSpan->getName());
        $this->assertSame(SpanKind::KIND_INTERNAL, $handleSpan->getKind());

        $convertSpan = $this->spanFactory->createChildSpan('symfony.response.convert');
        $this->assertSame('symfony.response.convert', $convertSpan->getName());

        $resetSpan = $this->spanFactory->createChildSpan('symfony.reset');
        $this->assertSame('symfony.reset', $resetSpan->getName());
    }

    public function testRootSpanNameIncludesMethodAndUrl(): void
    {
        $request = new IntegrationFakeSwooleRequest('DELETE', '/api/items/42', [
            'x-request-id' => 'otel-name-1',
        ]);

        $rootSpan = $this->listener->beforeHandle($request);

        $this->assertSame('HTTP DELETE /api/items/42', $rootSpan->getName());
    }
}
