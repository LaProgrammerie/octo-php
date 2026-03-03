<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

/**
 * In-memory span exporter for testing without the OTEL SDK.
 *
 * Collects exported spans for assertion.
 */
final class FakeSpanExporter implements SpanExporterInterface
{
    /** @var list<list<SpanInterface>> */
    private array $exportedBatches = [];

    private bool $shouldFail = false;
    private bool $isShutdown = false;

    public function export(array $spans): bool
    {
        if ($this->shouldFail) {
            return false;
        }

        $this->exportedBatches[] = $spans;
        return true;
    }

    public function shutdown(): bool
    {
        $this->isShutdown = true;
        return true;
    }

    /**
     * Configure the exporter to fail on next export.
     */
    public function setShouldFail(bool $shouldFail): void
    {
        $this->shouldFail = $shouldFail;
    }

    /**
     * @return list<list<SpanInterface>>
     */
    public function getExportedBatches(): array
    {
        return $this->exportedBatches;
    }

    /**
     * Returns all exported spans flattened.
     *
     * @return list<SpanInterface>
     */
    public function getAllExportedSpans(): array
    {
        return array_merge([], ...$this->exportedBatches);
    }

    public function isShutdown(): bool
    {
        return $this->isShutdown;
    }
}
