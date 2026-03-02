<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyOtel;

use AsyncPlatform\SymfonyOtel\Tracing\SpanInterface;
use AsyncPlatform\SymfonyOtel\Tracing\SpanKind;
use AsyncPlatform\SymfonyOtel\Tracing\TracerInterface;

/**
 * Factory for creating OTEL spans in the Symfony bridge.
 *
 * Creates:
 * - Root spans (SpanKind::KIND_SERVER) with HTTP attributes
 * - Child spans (SpanKind::KIND_INTERNAL) for bridge lifecycle phases
 *
 * Root span attributes: http.method, http.url, http.request_id
 * Child span names: symfony.kernel.handle, symfony.response.convert, symfony.reset
 *
 * Supports W3C trace context propagation via parent context.
 */
final class OtelSpanFactory
{
    public function __construct(
        private readonly TracerInterface $tracer,
    ) {
    }

    /**
     * Create a root span for an incoming HTTP request.
     *
     * @param string $requestId  The X-Request-Id header value
     * @param string $method     HTTP method (GET, POST, etc.)
     * @param string $url        Request URI
     * @param array<string, string>|null $parentContext Extracted W3C trace context
     */
    public function createRootSpan(
        string $requestId,
        string $method,
        string $url,
        ?array $parentContext = null,
    ): SpanInterface {
        $builder = $this->tracer->spanBuilder("HTTP {$method} {$url}")
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.method', $method)
            ->setAttribute('http.url', $url)
            ->setAttribute('http.request_id', $requestId);

        if ($parentContext !== null && $parentContext !== []) {
            $builder->setParent($parentContext);
        }

        return $builder->startSpan();
    }

    /**
     * Create a child span for a bridge lifecycle phase.
     *
     * Expected names: symfony.kernel.handle, symfony.response.convert, symfony.reset
     */
    public function createChildSpan(string $name): SpanInterface
    {
        return $this->tracer->spanBuilder($name)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();
    }
}
