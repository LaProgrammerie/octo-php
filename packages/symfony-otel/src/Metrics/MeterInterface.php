<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyOtel\Metrics;

/**
 * Abstraction over OpenTelemetry\API\Metrics\MeterInterface for testability.
 *
 * Provides counter and histogram instruments for OTEL metrics export.
 * In production, the real OTEL SDK meter is used via an adapter.
 * In tests, a FakeMeter is injected.
 */
interface MeterInterface
{
    /**
     * Record a counter value (monotonically increasing).
     */
    public function counter(string $name, int $value, string $description = ''): void;

    /**
     * Record a histogram observation.
     */
    public function histogram(string $name, float $value, string $description = ''): void;
}
