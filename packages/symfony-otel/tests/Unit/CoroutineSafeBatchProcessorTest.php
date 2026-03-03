<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tests\Unit;

use Octo\SymfonyOtel\CoroutineSafeBatchProcessor;
use Octo\SymfonyOtel\Tracing\FakeSpan;
use Octo\SymfonyOtel\Tracing\FakeSpanExporter;
use Octo\SymfonyOtel\Tracing\SpanKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CoroutineSafeBatchProcessorTest extends TestCase
{
    private function makeSpan(string $name = 'test-span'): FakeSpan
    {
        return new FakeSpan($name, SpanKind::KIND_INTERNAL);
    }

    #[Test]
    public function onEndBuffersSpans(): void
    {
        $exporter = new FakeSpanExporter();
        $processor = new CoroutineSafeBatchProcessor($exporter, batchSize: 10);

        $processor->onEnd($this->makeSpan());
        $processor->onEnd($this->makeSpan());

        self::assertSame(2, $processor->getPendingCount());
        self::assertEmpty($exporter->getExportedBatches());
    }

    #[Test]
    public function flushWhenBatchFull(): void
    {
        $exporter = new FakeSpanExporter();
        $processor = new CoroutineSafeBatchProcessor($exporter, batchSize: 3);

        $processor->onEnd($this->makeSpan('span-1'));
        $processor->onEnd($this->makeSpan('span-2'));

        // Not yet flushed
        self::assertEmpty($exporter->getExportedBatches());

        // Third span triggers flush
        $processor->onEnd($this->makeSpan('span-3'));

        self::assertCount(1, $exporter->getExportedBatches());
        self::assertCount(3, $exporter->getAllExportedSpans());
        self::assertSame(0, $processor->getPendingCount());
    }

    #[Test]
    public function forceFlushExportsBufferedSpans(): void
    {
        $exporter = new FakeSpanExporter();
        $processor = new CoroutineSafeBatchProcessor($exporter, batchSize: 100);

        $processor->onEnd($this->makeSpan());
        $processor->onEnd($this->makeSpan());

        $result = $processor->forceFlush();

        self::assertTrue($result);
        self::assertCount(1, $exporter->getExportedBatches());
        self::assertCount(2, $exporter->getAllExportedSpans());
        self::assertSame(0, $processor->getPendingCount());
    }

    #[Test]
    public function forceFlushWithEmptyBatchReturnsTrue(): void
    {
        $exporter = new FakeSpanExporter();
        $processor = new CoroutineSafeBatchProcessor($exporter);

        self::assertTrue($processor->forceFlush());
        self::assertEmpty($exporter->getExportedBatches());
    }

    #[Test]
    public function shutdownFlushesAndShutdownsExporter(): void
    {
        $exporter = new FakeSpanExporter();
        $processor = new CoroutineSafeBatchProcessor($exporter, batchSize: 100);

        $processor->onEnd($this->makeSpan());

        $result = $processor->shutdown();

        self::assertTrue($result);
        self::assertCount(1, $exporter->getAllExportedSpans());
        self::assertTrue($exporter->isShutdown());
    }

    #[Test]
    public function shutdownClearsTimer(): void
    {
        $timerCleared = false;
        $timerStarter = fn(int $ms, callable $cb): int => 42;
        $timerClearer = function (int $id) use (&$timerCleared): void {
            $timerCleared = true;
            self::assertSame(42, $id);
        };

        $exporter = new FakeSpanExporter();
        $processor = new CoroutineSafeBatchProcessor(
            $exporter,
            timerStarter: $timerStarter,
            timerClearer: $timerClearer,
        );

        $processor->startPeriodicExport();
        $processor->shutdown();

        self::assertTrue($timerCleared);
    }

    #[Test]
    public function onStartIsNoOp(): void
    {
        $exporter = new FakeSpanExporter();
        $processor = new CoroutineSafeBatchProcessor($exporter);

        $span = $this->makeSpan();
        $processor->onStart($span);

        self::assertSame(0, $processor->getPendingCount());
        self::assertEmpty($exporter->getExportedBatches());
    }

    #[Test]
    public function startPeriodicExportRegistersTimer(): void
    {
        $timerStarted = false;
        $capturedInterval = 0;
        $timerStarter = function (int $ms, callable $cb) use (&$timerStarted, &$capturedInterval): int {
            $timerStarted = true;
            $capturedInterval = $ms;
            return 1;
        };

        $exporter = new FakeSpanExporter();
        $processor = new CoroutineSafeBatchProcessor(
            $exporter,
            exportIntervalMs: 3000,
            timerStarter: $timerStarter,
        );

        $processor->startPeriodicExport();

        self::assertTrue($timerStarted);
        self::assertSame(3000, $capturedInterval);
    }

    #[Test]
    public function startPeriodicExportIdempotent(): void
    {
        $callCount = 0;
        $timerStarter = function (int $ms, callable $cb) use (&$callCount): int {
            $callCount++;
            return 1;
        };

        $exporter = new FakeSpanExporter();
        $processor = new CoroutineSafeBatchProcessor(
            $exporter,
            timerStarter: $timerStarter,
        );

        $processor->startPeriodicExport();
        $processor->startPeriodicExport();

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function periodicTimerCallbackFlushesSpans(): void
    {
        $capturedCallback = null;
        $timerStarter = function (int $ms, callable $cb) use (&$capturedCallback): int {
            $capturedCallback = $cb;
            return 1;
        };

        $exporter = new FakeSpanExporter();
        $processor = new CoroutineSafeBatchProcessor(
            $exporter,
            batchSize: 100,
            timerStarter: $timerStarter,
        );

        $processor->startPeriodicExport();
        $processor->onEnd($this->makeSpan());
        $processor->onEnd($this->makeSpan());

        // Simulate timer tick
        self::assertNotNull($capturedCallback);
        $capturedCallback();

        self::assertCount(2, $exporter->getAllExportedSpans());
        self::assertSame(0, $processor->getPendingCount());
    }

    #[Test]
    public function exportFailureReturnsFalse(): void
    {
        $exporter = new FakeSpanExporter();
        $exporter->setShouldFail(true);

        $processor = new CoroutineSafeBatchProcessor($exporter, batchSize: 100);
        $processor->onEnd($this->makeSpan());

        $result = $processor->forceFlush();

        self::assertFalse($result);
        self::assertSame(0, $processor->getPendingCount()); // batch was cleared even on failure
    }

    #[Test]
    public function multipleBatchFlushes(): void
    {
        $exporter = new FakeSpanExporter();
        $processor = new CoroutineSafeBatchProcessor($exporter, batchSize: 2);

        // First batch
        $processor->onEnd($this->makeSpan('a'));
        $processor->onEnd($this->makeSpan('b'));

        // Second batch
        $processor->onEnd($this->makeSpan('c'));
        $processor->onEnd($this->makeSpan('d'));

        self::assertCount(2, $exporter->getExportedBatches());
        self::assertCount(4, $exporter->getAllExportedSpans());
    }
}
