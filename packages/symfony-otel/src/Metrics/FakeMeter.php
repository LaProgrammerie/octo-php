<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Metrics;

use Override;

/**
 * In-memory meter for testing without the OTEL SDK.
 *
 * Records all counter and histogram values for assertion.
 */
final class FakeMeter implements MeterInterface
{
    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, list<float>> */
    private array $histograms = [];

    #[Override]
    public function counter(string $name, int $value, string $description = ''): void
    {
        $this->counters[$name] = ($this->counters[$name] ?? 0) + $value;
    }

    #[Override]
    public function histogram(string $name, float $value, string $description = ''): void
    {
        $this->histograms[$name][] = $value;
    }

    public function getCounter(string $name): int
    {
        return $this->counters[$name] ?? 0;
    }

    /**
     * @return list<float>
     */
    public function getHistogramValues(string $name): array
    {
        return $this->histograms[$name] ?? [];
    }

    /**
     * @return array<string, int>
     */
    public function getAllCounters(): array
    {
        return $this->counters;
    }

    /**
     * @return array<string, list<float>>
     */
    public function getAllHistograms(): array
    {
        return $this->histograms;
    }
}
