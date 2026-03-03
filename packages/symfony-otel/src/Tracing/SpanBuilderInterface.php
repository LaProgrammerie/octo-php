<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

/**
 * Abstraction over OpenTelemetry\API\Trace\SpanBuilderInterface.
 */
interface SpanBuilderInterface
{
    public function setSpanKind(int $spanKind): self;

    public function setAttribute(string $key, bool|float|int|string $value): self;

    /**
     * Set the parent context for trace propagation.
     *
     * @param array<string, string> $parentContext Extracted W3C trace context headers
     */
    public function setParent(array $parentContext): self;

    public function startSpan(): SpanInterface;
}
