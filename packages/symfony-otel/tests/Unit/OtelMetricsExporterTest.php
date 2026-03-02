<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyOtel\Tests\Unit;

use AsyncPlatform\RuntimePack\MetricsCollector;
use AsyncPlatform\SymfonyBridge\MetricsBridge;
use AsyncPlatform\SymfonyOtel\Metrics\FakeMeter;
use AsyncPlatform\SymfonyOtel\OtelMetricsExporter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OtelMetricsExporterTest extends TestCase
{
    private function createExporter(): array
    {
        $collector = new MetricsCollector();
        $bridge = new MetricsBridge($collector);
        $meter = new FakeMeter();
        $exporter = new OtelMetricsExporter($bridge, $meter);

        return [$exporter, $bridge, $meter];
    }

    #[Test]
    public function exportRequestsTotal(): void
    {
        [$exporter, $bridge, $meter] = $this->createExporter();

        $bridge->incrementRequests();
        $bridge->incrementRequests();
        $bridge->incrementRequests();

        $exporter->export();

        self::assertSame(3, $meter->getCounter('symfony_requests_total'));
    }

    #[Test]
    public function exportExceptionsTotal(): void
    {
        [$exporter, $bridge, $meter] = $this->createExporter();

        $bridge->incrementExceptions();
        $bridge->incrementExceptions();

        $exporter->export();

        self::assertSame(2, $meter->getCounter('symfony_exceptions_total'));
    }

    #[Test]
    public function exportRequestDuration(): void
    {
        [$exporter, $bridge, $meter] = $this->createExporter();

        $bridge->recordRequestDuration(15.5);
        $bridge->recordRequestDuration(22.3);

        $exporter->export();

        $values = $meter->getHistogramValues('symfony_request_duration_ms');
        self::assertCount(1, $values);
        self::assertEqualsWithDelta(37.8, $values[0], 0.01);
    }

    #[Test]
    public function exportResetDuration(): void
    {
        [$exporter, $bridge, $meter] = $this->createExporter();

        $bridge->recordResetDuration(2.1);
        $bridge->recordResetDuration(3.4);

        $exporter->export();

        $values = $meter->getHistogramValues('symfony_reset_duration_ms');
        self::assertCount(1, $values);
        self::assertEqualsWithDelta(5.5, $values[0], 0.01);
    }

    #[Test]
    public function exportDeltaOnlyExportsNewValues(): void
    {
        [$exporter, $bridge, $meter] = $this->createExporter();

        $bridge->incrementRequests();
        $bridge->incrementRequests();
        $exporter->export();

        self::assertSame(2, $meter->getCounter('symfony_requests_total'));

        // Second export with 1 more request
        $bridge->incrementRequests();
        $exporter->export();

        // Total should be 2 + 1 = 3
        self::assertSame(3, $meter->getCounter('symfony_requests_total'));
    }

    #[Test]
    public function exportWithNoChangesDoesNothing(): void
    {
        [$exporter, $bridge, $meter] = $this->createExporter();

        $exporter->export();

        self::assertSame(0, $meter->getCounter('symfony_requests_total'));
        self::assertSame(0, $meter->getCounter('symfony_exceptions_total'));
        self::assertEmpty($meter->getHistogramValues('symfony_request_duration_ms'));
        self::assertEmpty($meter->getHistogramValues('symfony_reset_duration_ms'));
    }

    #[Test]
    public function exportAllFourMetricsTogether(): void
    {
        [$exporter, $bridge, $meter] = $this->createExporter();

        $bridge->incrementRequests();
        $bridge->incrementExceptions();
        $bridge->recordRequestDuration(10.0);
        $bridge->recordResetDuration(1.5);

        $exporter->export();

        self::assertSame(1, $meter->getCounter('symfony_requests_total'));
        self::assertSame(1, $meter->getCounter('symfony_exceptions_total'));
        self::assertEqualsWithDelta(10.0, $meter->getHistogramValues('symfony_request_duration_ms')[0], 0.01);
        self::assertEqualsWithDelta(1.5, $meter->getHistogramValues('symfony_reset_duration_ms')[0], 0.01);
    }
}
