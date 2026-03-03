<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

/**
 * Default W3C Trace Context propagator.
 *
 * Extracts traceparent and tracestate headers from HTTP request headers.
 * In production, this can be replaced by the real OTEL SDK propagator.
 *
 * @see https://www.w3.org/TR/trace-context/
 */
final class W3CTraceContextPropagator implements TextMapPropagatorInterface
{
    public function extract(array $carrier): array
    {
        $context = [];

        // W3C traceparent: version-trace_id-parent_id-trace_flags
        if (isset($carrier['traceparent']) && $carrier['traceparent'] !== '') {
            $context['traceparent'] = $carrier['traceparent'];
        }

        // W3C tracestate: vendor-specific key=value pairs
        if (isset($carrier['tracestate']) && $carrier['tracestate'] !== '') {
            $context['tracestate'] = $carrier['tracestate'];
        }

        return $context;
    }
}
