<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel;

use Octo\SymfonyBridge\MetricsBridge;
use Octo\SymfonyOtel\Metrics\MeterInterface;

/**
 * Exports metrics from the Symfony bridge MetricsBridge to OTEL.
 *
 * Reads the MetricsBridge snapshot and pushes values to the OTEL meter:
 * - symfony_requests_total (counter)
 * - symfony_request_duration_ms (histogram)
 * - symfony_exceptions_total (counter)
 * - symfony_reset_duration_ms (histogram)
 *
 * Designed to be called periodically (e.g., via OpenSwoole timer)
 * or on-demand after each request cycle.
 */
final class OtelMetricsExporter
{
    private int $lastRequestsTotal = 0;
    private int $lastExceptionsTotal = 0;
    private float $lastRequestDurationSumMs = 0.0;
    private float $lastResetDurationSumMs = 0.0;

    public function __construct(
        private readonly MetricsBridge $metricsBridge,
        private readonly MeterInterface $meter,
    ) {}

    /**
     * Export delta metrics since last export to the OTEL meter.
     *
     * Uses delta computation to avoid double-counting: only the difference
     * since the last export is pushed to OTEL instruments.
     */
    public function export(): void
    {
        $snapshot = $this->metricsBridge->snapshot();

        // Counter deltas
        /** @var int $requestsTotal */
        $requestsTotal = $snapshot['symfony_requests_total'] ?? 0;
        $requestsDelta = $requestsTotal - $this->lastRequestsTotal;
        if ($requestsDelta > 0) {
            $this->meter->counter(
                'symfony_requests_total',
                $requestsDelta,
                'Total HTTP requests processed by the Symfony bridge',
            );
            $this->lastRequestsTotal = $requestsTotal;
        }

        /** @var int $exceptionsTotal */
        $exceptionsTotal = $snapshot['symfony_exceptions_total'] ?? 0;
        $exceptionsDelta = $exceptionsTotal - $this->lastExceptionsTotal;
        if ($exceptionsDelta > 0) {
            $this->meter->counter(
                'symfony_exceptions_total',
                $exceptionsDelta,
                'Total exceptions raised by HttpKernel',
            );
            $this->lastExceptionsTotal = $exceptionsTotal;
        }

        // Histogram deltas (sum-based — we export the delta as a single observation)
        /** @var float $requestDurationSum */
        $requestDurationSum = $snapshot['symfony_request_duration_sum_ms'] ?? 0.0;
        $requestDurationDelta = $requestDurationSum - $this->lastRequestDurationSumMs;
        if ($requestDurationDelta > 0.0) {
            $this->meter->histogram(
                'symfony_request_duration_ms',
                $requestDurationDelta,
                'Duration of HttpKernel::handle() in milliseconds',
            );
            $this->lastRequestDurationSumMs = $requestDurationSum;
        }

        /** @var float $resetDurationSum */
        $resetDurationSum = $snapshot['symfony_reset_duration_sum_ms'] ?? 0.0;
        $resetDurationDelta = $resetDurationSum - $this->lastResetDurationSumMs;
        if ($resetDurationDelta > 0.0) {
            $this->meter->histogram(
                'symfony_reset_duration_ms',
                $resetDurationDelta,
                'Duration of reset between requests in milliseconds',
            );
            $this->lastResetDurationSumMs = $resetDurationSum;
        }
    }
}
