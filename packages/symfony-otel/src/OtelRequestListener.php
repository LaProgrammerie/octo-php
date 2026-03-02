<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyOtel;

use AsyncPlatform\SymfonyOtel\Tracing\SpanInterface;
use AsyncPlatform\SymfonyOtel\Tracing\StatusCode;
use AsyncPlatform\SymfonyOtel\Tracing\TextMapPropagatorInterface;

/**
 * OTEL listener integrated into the HttpKernelAdapter lifecycle.
 *
 * Lifecycle of the root span:
 * 1. beforeHandle(): create root span BEFORE HttpKernel::handle()
 *    - Propagate incoming W3C trace context (traceparent/tracestate)
 *    - Attributes: http.method, http.url, http.request_id
 * 2. afterHandle(): enrich root span AFTER reset/terminate
 *    - Attributes: http.status_code, symfony.route, symfony.controller
 *    - End the root span
 * 3. onException(): capture exception in the root span
 *    - If exception occurs before child spans, root span still captures it and ends correctly
 */
final class OtelRequestListener
{
    public function __construct(
        private readonly OtelSpanFactory $spanFactory,
        private readonly TextMapPropagatorInterface $propagator,
    ) {
    }

    /**
     * Create the root span and extract incoming trace context.
     *
     * Called BEFORE HttpKernel::handle().
     *
     * @param object $swooleRequest OpenSwoole Request (for W3C headers)
     * @return SpanInterface The active root span
     */
    public function beforeHandle(object $swooleRequest): SpanInterface
    {
        $headers = $swooleRequest->header ?? [];
        $server = $swooleRequest->server ?? [];

        // Extract W3C trace context from incoming headers
        $parentContext = $this->propagator->extract($headers);

        return $this->spanFactory->createRootSpan(
            requestId: $headers['x-request-id'] ?? 'unknown',
            method: strtoupper($server['request_method'] ?? 'GET'),
            url: $server['request_uri'] ?? '/',
            parentContext: $parentContext !== [] ? $parentContext : null,
        );
    }

    /**
     * Enrich and end the root span after the full request lifecycle.
     *
     * Called AFTER reset/terminate.
     */
    public function afterHandle(
        SpanInterface $rootSpan,
        int $statusCode,
        ?string $route = null,
        ?string $controller = null,
    ): void {
        $rootSpan->setAttribute('http.status_code', $statusCode);

        if ($route !== null) {
            $rootSpan->setAttribute('symfony.route', $route);
        }
        if ($controller !== null) {
            $rootSpan->setAttribute('symfony.controller', $controller);
        }

        $rootSpan->end();
    }

    /**
     * Capture an exception in the root span.
     *
     * If exception occurs before child spans are created, the root span
     * still captures the exception and can be ended correctly via afterHandle().
     */
    public function onException(SpanInterface $rootSpan, \Throwable $e): void
    {
        $rootSpan->recordException($e);
        $rootSpan->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
    }
}
