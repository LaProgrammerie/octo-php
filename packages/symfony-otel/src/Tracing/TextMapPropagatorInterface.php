<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

/**
 * Abstraction over OpenTelemetry\Context\Propagation\TextMapPropagatorInterface.
 *
 * Extracts W3C trace context (traceparent, tracestate) from HTTP headers.
 */
interface TextMapPropagatorInterface
{
    /**
     * Extract trace context from carrier (HTTP headers).
     *
     * @param array<string, string> $carrier HTTP headers (lowercase keys)
     * @return array<string, string> Extracted context (traceparent, tracestate)
     */
    public function extract(array $carrier): array;
}
