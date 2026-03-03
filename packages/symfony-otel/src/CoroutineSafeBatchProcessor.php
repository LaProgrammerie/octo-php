<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel;

use Closure;
use Octo\SymfonyOtel\Tracing\SpanExporterInterface;
use Octo\SymfonyOtel\Tracing\SpanInterface;
use Octo\SymfonyOtel\Tracing\SpanProcessorInterface;
use Override;

use function count;

/**
 * Coroutine-safe batch span processor.
 *
 * Collects spans in an in-memory batch and flushes when:
 * - The batch reaches the configured size (batchSize)
 * - A periodic timer ticks (exportIntervalMs)
 * - forceFlush() is called explicitly
 *
 * The timer and coroutine spawning are injectable for testability:
 * - Production: OpenSwoole\Timer::tick() for periodic export
 * - Tests: synchronous flush (no timer)
 *
 * Configuration via OTEL_EXPORTER_OTLP_ENDPOINT (standard OTEL env var).
 * No custom environment variables.
 */
final class CoroutineSafeBatchProcessor implements SpanProcessorInterface
{
    /** @var list<SpanInterface> */
    private array $batch = [];

    private ?int $timerId = null;

    private readonly Closure $timerStarter; // @phpstan-ignore missingType.callable

    private readonly Closure $timerClearer; // @phpstan-ignore missingType.callable

    /**
     * @phpstan-param null|\Closure(int, callable): int $timerStarter
     * @phpstan-param null|\Closure(int): void $timerClearer
     */
    public function __construct(// @phpstan-ignore missingType.callable
        private readonly SpanExporterInterface $exporter,
        private readonly int $batchSize = 512,
        private readonly int $exportIntervalMs = 5000,
        ?Closure $timerStarter = null,
        ?Closure $timerClearer = null,
    ) {
        $this->timerStarter = $timerStarter ?? static fn (int $ms, callable $cb): int => 0;
        $this->timerClearer = $timerClearer ?? static fn (int $id): null => null;
    }

    #[Override]
    public function onStart(SpanInterface $span): void
    {
        // No-op for batch processor — spans are collected on end.
    }

    #[Override]
    public function onEnd(SpanInterface $span): void
    {
        $this->batch[] = $span;

        if (count($this->batch) >= $this->batchSize) {
            $this->flush();
        }
    }

    #[Override]
    public function forceFlush(): bool
    {
        return $this->flush();
    }

    #[Override]
    public function shutdown(): bool
    {
        if ($this->timerId !== null) {
            ($this->timerClearer)($this->timerId);
            $this->timerId = null;
        }

        $flushed = $this->flush();
        $shutdown = $this->exporter->shutdown();

        return $flushed && $shutdown;
    }

    /**
     * Start the periodic export timer.
     *
     * Called at worker boot. Uses the injectable timer starter
     * (OpenSwoole\Timer::tick in production).
     */
    public function startPeriodicExport(): void
    {
        if ($this->timerId !== null) {
            return;
        }

        $this->timerId = ($this->timerStarter)(
            $this->exportIntervalMs,
            fn () => $this->flush(),
        );
    }

    /**
     * Returns the current batch size (for testing/monitoring).
     */
    public function getPendingCount(): int
    {
        return count($this->batch);
    }

    private function flush(): bool
    {
        if ($this->batch === []) {
            return true;
        }

        $spans = $this->batch;
        $this->batch = [];

        return $this->exporter->export($spans);
    }
}
