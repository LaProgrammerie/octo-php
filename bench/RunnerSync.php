<?php

declare(strict_types=1);

namespace AsyncPlatform\Bench;

/**
 * Sequential (blocking) runner — baseline for comparison.
 *
 * Metrics model per job (same shape as async for consistency):
 *   queue_wait_ns = always 0 (no queuing in sync)
 *   exec_ns       = actual job execution time
 *   cpu_ns        = CPU-only portion (hashing + JSON)
 *   io_ns         = IO-only portion (sleep)
 *   e2e_ns        = same as exec_ns
 */
final class RunnerSync
{
    /**
     * @return array{
     *     total_ns: int,
     *     queue_wait_ns: int[],
     *     exec_ns: int[],
     *     cpu_ns: int[],
     *     io_ns: int[],
     *     e2e_ns: int[],
     *     rss_kb: int|null
     * }
     */
    public function run(int $jobs, int $cpuIterations, int $ioMs, int $jsonKb): array
    {
        $job = new Job(
            cpuIterations: $cpuIterations,
            ioMs: $ioMs,
            jsonKb: $jsonKb,
            async: false,
        );

        $queueWaitNs = [];
        $execNs = [];
        $cpuNs = [];
        $ioNs = [];
        $e2eNs = [];

        $startTotal = hrtime(true);

        for ($i = 0; $i < $jobs; $i++) {
            $start = hrtime(true);
            $result = $job($i);
            $elapsed = hrtime(true) - $start;

            $queueWaitNs[] = 0;
            $execNs[] = $elapsed;
            $cpuNs[] = $result['cpu_ns'];
            $ioNs[] = $result['io_ns'];
            $e2eNs[] = $elapsed;
        }

        $totalNs = hrtime(true) - $startTotal;

        return [
            'total_ns' => $totalNs,
            'queue_wait_ns' => $queueWaitNs,
            'exec_ns' => $execNs,
            'cpu_ns' => $cpuNs,
            'io_ns' => $ioNs,
            'e2e_ns' => $e2eNs,
            'rss_kb' => self::readRssKb(),
        ];
    }

    private static function readRssKb(): ?int
    {
        $statusFile = '/proc/self/status';
        if (!is_readable($statusFile)) {
            return null;
        }

        $content = file_get_contents($statusFile);
        if ($content === false) {
            return null;
        }

        if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $content, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
