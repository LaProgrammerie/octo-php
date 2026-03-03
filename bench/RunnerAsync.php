<?php

declare(strict_types=1);

namespace Octo\Bench;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

/**
 * Concurrent runner using a fixed worker-pool of coroutines.
 *
 * Architecture:
 *   - A bounded Channel<int> acts as a job queue (backpressure built-in).
 *   - N persistent worker coroutines pop jobs from the queue.
 *   - Each worker measures its own exec time with tight hrtime() around the job.
 *   - A producer coroutine pushes job indices + enqueue timestamps into the queue.
 *
 * Metrics per job:
 *   queue_wait_ns = tPickup - tEnqueue  (time the job sat in the channel)
 *   exec_ns       = tDone - tStart      (total job service time)
 *   cpu_ns        = CPU-only portion    (hashing + JSON, from Job)
 *   io_ns         = IO-only portion     (sleep, from Job)
 *   e2e_ns        = tDone - tEnqueue    (total user-perceived latency)
 *
 * Why worker-pool instead of spawn-per-job:
 *   - No Coroutine::create() storm (avoids benchmarking coroutine creation)
 *   - Bounded memory (exactly N coroutines, not jobs×coroutines)
 *   - Closer to how a real runtime would schedule work
 */
final class RunnerAsync
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
    public function run(int $jobs, int $concurrency, int $cpuIterations, int $ioMs, int $jsonKb, int $yieldEvery = 0): array
    {
        if ($concurrency <= 0) {
            throw new \InvalidArgumentException('Concurrency must be > 0');
        }

        $totalNs = 0;
        $metrics = [];
        $rssKb = null;

        Coroutine::run(function () use ($jobs, $concurrency, $cpuIterations, $ioMs, $jsonKb, $yieldEvery, &$totalNs, &$metrics, &$rssKb, ): void {
            $job = new Job(
                cpuIterations: $cpuIterations,
                ioMs: $ioMs,
                jsonKb: $jsonKb,
                async: true,
                yieldEvery: $yieldEvery,
            );

            // Job queue: bounded channel. Each item = [jobIndex, tEnqueue].
            // Buffer size = concurrency to avoid producer blocking too early,
            // but still bounded (backpressure if workers are slow).
            $queue = new Channel($concurrency);

            // Results channel: workers push metrics here.
            $results = new Channel($jobs);

            $startTotal = hrtime(true);

            // ── Spawn N persistent workers ──────────────────────────────
            for ($w = 0; $w < $concurrency; $w++) {
                Coroutine::create(function () use ($job, $queue, $results): void {
                    while (true) {
                        $item = $queue->pop();

                        // Poison pill: null means "shut down"
                        if ($item === null) {
                            return;
                        }

                        [$jobIndex, $tEnqueue] = $item;

                        // tPickup: worker just grabbed the job from queue
                        $tPickup = hrtime(true);

                        // tStart: about to execute
                        $tStart = hrtime(true);
                        $jobResult = $job($jobIndex);
                        $tDone = hrtime(true);

                        $results->push([
                            'queue_wait' => $tPickup - $tEnqueue,
                            'exec' => $tDone - $tStart,
                            'cpu' => $jobResult['cpu_ns'],
                            'io' => $jobResult['io_ns'],
                            'e2e' => $tDone - $tEnqueue,
                        ]);
                    }
                });
            }

            // ── Producer: enqueue all jobs ──────────────────────────────
            for ($i = 0; $i < $jobs; $i++) {
                $tEnqueue = hrtime(true);
                $queue->push([$i, $tEnqueue]);
            }

            // ── Collect all results ─────────────────────────────────────
            for ($i = 0; $i < $jobs; $i++) {
                $metrics[] = $results->pop();
            }

            // ── Send poison pills to shut down workers ──────────────────
            for ($w = 0; $w < $concurrency; $w++) {
                $queue->push(null);
            }

            $totalNs = hrtime(true) - $startTotal;
            $rssKb = self::readRssKb();
        });

        $queueWaitNs = array_column($metrics, 'queue_wait');
        $execNs = array_column($metrics, 'exec');
        $cpuNs = array_column($metrics, 'cpu');
        $ioNs = array_column($metrics, 'io');
        $e2eNs = array_column($metrics, 'e2e');

        return [
            'total_ns' => $totalNs,
            'queue_wait_ns' => $queueWaitNs,
            'exec_ns' => $execNs,
            'cpu_ns' => $cpuNs,
            'io_ns' => $ioNs,
            'e2e_ns' => $e2eNs,
            'rss_kb' => $rssKb,
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
