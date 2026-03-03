<?php

declare(strict_types=1);

namespace Octo\Bench;

use const JSON_THROW_ON_ERROR;

use OpenSwoole\Coroutine;

/**
 * Deterministic job simulating a realistic processing pipeline.
 *
 * 3 stages:
 *  1) CPU — iterative SHA-256 hashing + JSON encode/decode
 *  2) IO  — simulated sleep (sync: usleep / async: Coroutine::sleep)
 *
 * Returns a JobResult with separate cpu_ns / io_ns timings so the bench
 * can distinguish CPU saturation from IO overlap.
 */
final class Job
{
    /**
     * @param int $cpuIterations Number of SHA-256 rounds
     * @param int $ioMs Simulated IO latency in milliseconds
     * @param int $jsonKb Approximate JSON payload size in KB
     * @param bool $async Use coroutine sleep instead of usleep
     * @param int $yieldEvery Cooperative yield every N CPU iterations (0 = disabled)
     */
    public function __construct(
        private readonly int $cpuIterations = 20_000,
        private readonly int $ioMs = 5,
        private readonly int $jsonKb = 8,
        private readonly bool $async = false,
        private readonly int $yieldEvery = 0,
    ) {}

    /**
     * Execute the job and return a result with split cpu/io timings.
     *
     * @return array{digest: string, cpu_ns: int, io_ns: int}
     */
    public function __invoke(int $jobIndex): array
    {
        // ── Stage 1: CPU-bound (hashing + JSON) ────────────────────
        $cpuStart = hrtime(true);
        $yieldPauseNs = 0;

        $hash = hash('sha256', "seed-{$jobIndex}");
        $yieldEvery = $this->yieldEvery;
        for ($i = 0; $i < $this->cpuIterations; ++$i) {
            $hash = hash('sha256', $hash . $i);

            // Cooperative yield: give scheduler a chance to resume IO-waiting coroutines.
            // Yield time is excluded from cpu_ns to keep the metric accurate.
            if ($yieldEvery > 0 && $i > 0 && $i % $yieldEvery === 0) {
                $yieldStart = hrtime(true);
                Coroutine::usleep(0);
                $yieldPauseNs += hrtime(true) - $yieldStart;
            }
        }

        $payload = $this->buildPayload();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        $cpuNs = (int) (hrtime(true) - $cpuStart - $yieldPauseNs);

        // ── Stage 2: IO-bound (simulated latency) ──────────────────
        $ioStart = hrtime(true);

        if ($this->ioMs > 0) {
            if ($this->async) {
                Coroutine::usleep($this->ioMs * 1000);
            } else {
                usleep($this->ioMs * 1000);
            }
        }

        $ioNs = (int) (hrtime(true) - $ioStart);

        $digest = hash('sha256', $hash . ($decoded['checksum'] ?? ''));

        return [
            'digest' => $digest,
            'cpu_ns' => $cpuNs,
            'io_ns' => $ioNs,
        ];
    }

    /**
     * Build a deterministic payload of approximately $jsonKb kilobytes.
     *
     * @return array{items: list<array{id: int, value: string, flag: bool}>, checksum: string}
     */
    private function buildPayload(): array
    {
        $itemSize = 64; // ~64 bytes per item after JSON encoding
        $targetBytes = $this->jsonKb * 1024;
        $count = max(1, intdiv($targetBytes, $itemSize));

        $items = [];
        for ($i = 0; $i < $count; ++$i) {
            $items[] = [
                'id' => $i,
                'value' => str_repeat('x', 32),
                'flag' => $i % 2 === 0,
            ];
        }

        return [
            'items' => $items,
            'checksum' => hash('crc32b', (string) $count),
        ];
    }
}
