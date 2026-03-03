<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

/**
 * Abstraction over OpenTelemetry\API\Trace\TracerInterface for testability.
 *
 * In production, the real OTEL SDK tracer is used via an adapter.
 * In tests, a FakeTracer is injected.
 */
interface TracerInterface
{
    public function spanBuilder(string $name): SpanBuilderInterface;
}
