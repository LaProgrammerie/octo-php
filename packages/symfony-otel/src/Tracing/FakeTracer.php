<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

use Override;

/**
 * In-memory tracer for testing without the OTEL SDK.
 *
 * Records all created spans for assertion in tests.
 */
final class FakeTracer implements TracerInterface
{
    /** @var list<SpanInterface> */
    private array $createdSpans = [];

    #[Override]
    public function spanBuilder(string $name): SpanBuilderInterface
    {
        return new FakeSpanBuilder($name);
    }

    /**
     * Track a span created by this tracer (for test assertions).
     */
    public function trackSpan(SpanInterface $span): void
    {
        $this->createdSpans[] = $span;
    }

    /**
     * @return list<SpanInterface>
     */
    public function getCreatedSpans(): array
    {
        return $this->createdSpans;
    }
}
