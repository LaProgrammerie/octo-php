<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

/**
 * Abstraction over OpenTelemetry\SDK\Trace\SpanExporterInterface.
 */
interface SpanExporterInterface
{
    /**
     * Export a batch of spans.
     *
     * @param list<SpanInterface> $spans
     *
     * @return bool true if export succeeded
     */
    public function export(array $spans): bool;

    /**
     * Shutdown the exporter, flushing any remaining data.
     */
    public function shutdown(): bool;
}
