<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyOtel\Tracing;

/**
 * Abstraction over OpenTelemetry\SDK\Trace\SpanProcessorInterface.
 */
interface SpanProcessorInterface
{
    /**
     * Called when a span is started (typically a no-op for batch processors).
     */
    public function onStart(SpanInterface $span): void;

    /**
     * Called when a span ends. The processor may buffer or export immediately.
     */
    public function onEnd(SpanInterface $span): void;

    /**
     * Force flush all buffered spans.
     */
    public function forceFlush(): bool;

    /**
     * Shutdown the processor, flushing remaining spans and releasing resources.
     */
    public function shutdown(): bool;
}
