<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge;

use Octo\RuntimePack\MetricsCollector;

/**
 * Bridge between the Symfony bridge and the runtime pack's MetricsCollector.
 *
 * Counters:
 * - symfony_requests_total
 * - symfony_exceptions_total
 *
 * Histograms:
 * - symfony_request_duration_ms
 * - symfony_reset_duration_ms
 *
 * Gauges:
 * - memory_rss_after_reset_bytes
 */
final class MetricsBridge
{
    private int $requestsTotal = 0;
    private int $exceptionsTotal = 0;
    private float $requestDurationSumMs = 0.0;
    private float $resetDurationSumMs = 0.0;
    private int $memoryRssAfterResetBytes = 0;

    public function __construct(
        private readonly MetricsCollector $collector,
    ) {
    }

    public function incrementRequests(): void
    {
        $this->requestsTotal++;
    }

    public function recordRequestDuration(float $ms): void
    {
        $this->requestDurationSumMs += $ms;
    }

    public function incrementExceptions(): void
    {
        $this->exceptionsTotal++;
    }

    public function recordResetDuration(float $ms): void
    {
        $this->resetDurationSumMs += $ms;
    }

    public function recordMemoryRss(int $bytes): void
    {
        $this->memoryRssAfterResetBytes = $bytes;
        $this->collector->setMemoryRss($bytes);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'symfony_requests_total' => $this->requestsTotal,
            'symfony_request_duration_sum_ms' => $this->requestDurationSumMs,
            'symfony_exceptions_total' => $this->exceptionsTotal,
            'symfony_reset_duration_sum_ms' => $this->resetDurationSumMs,
            'memory_rss_after_reset_bytes' => $this->memoryRssAfterResetBytes,
        ];
    }
}
