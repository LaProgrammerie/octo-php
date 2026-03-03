<?php

declare(strict_types=1);

namespace Octo\Bench;

use function assert;
use function count;
use function sprintf;

/**
 * Computes statistics on metric series and generates Markdown + CSV reports.
 *
 * Series: queue_wait, exec, cpu, io_wait, e2e.
 *
 * Utilization (mono-process coroutine model, parallelism=1):
 *   cpu_util    = cpu_sum / total_ms  → real CPU thread pressure
 *   worker_util = exec_sum / (total_ms × concurrency) → worker busy ratio (includes IO wait)
 *
 * Verdicts:
 *   BASELINE      — sync mode
 *   CPU_BOUND     — cpu_util > 85%
 *   SATURATED     — qw_p95 > 200ms or throughput regression with rising queue
 *   OK            — nominal
 *
 * Note: BACKPRESSURE detection requires a regulated arrival mode (Poisson/RPS).
 * In burst mode (current), queue_wait is always high — not a useful signal.
 */
final class Report
{
    private const RESULTS_DIR = __DIR__ . '/results';

    /**
     * @param array{
     *     mode: string,
     *     concurrency: null|int,
     *     total_ns: int,
     *     queue_wait_ns: list<int>,
     *     exec_ns: list<int>,
     *     cpu_ns: list<int>,
     *     io_ns: list<int>,
     *     e2e_ns: list<int>,
     *     rss_kb: null|int
     * } $run
     *
     * @return array{mode: string, jobs: int, concurrency: null|int, total_ms: float, jobs_per_sec: float, queue_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, exec: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, cpu: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, io_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, e2e: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, rss_mb: null|float}
     */
    public static function stats(array $run): array
    {
        $count = count($run['exec_ns']);
        $totalMs = $run['total_ns'] / 1_000_000;
        $jobsPerSec = $totalMs > 0 ? ($count / ($totalMs / 1000)) : 0;

        return [
            'mode' => $run['mode'],
            'jobs' => $count,
            'concurrency' => $run['concurrency'],
            'total_ms' => round($totalMs, 2),
            'jobs_per_sec' => round($jobsPerSec, 2),
            'queue_wait' => self::seriesStats($run['queue_wait_ns']),
            'exec' => self::seriesStats($run['exec_ns']),
            'cpu' => self::seriesStats($run['cpu_ns']),
            'io_wait' => self::seriesStats($run['io_ns']),
            'e2e' => self::seriesStats($run['e2e_ns']),
            'rss_mb' => $run['rss_kb'] !== null
                ? round($run['rss_kb'] / 1024, 2)
                : null,
        ];
    }

    /**
     * Compute utilization metrics and verdict.
     *
     * cpu_util uses parallelism=1 (mono-process coroutine, single CPU thread).
     * worker_util uses concurrency (logical worker slots).
     *
     * Verdicts: BASELINE | OK | CPU_BOUND | SATURATED | N/A_SMALL_SAMPLE | N/A_MICRO_BENCH
     *
     * @param array{mode: string, jobs: int, concurrency: null|int, total_ms: float, jobs_per_sec: float, queue_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, exec: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, cpu: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, io_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, e2e: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, rss_mb: null|float} $s
     * @param array{mode: string, jobs: int, concurrency: null|int, total_ms: float, jobs_per_sec: float, queue_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, exec: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, cpu: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, io_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, e2e: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, rss_mb: null|float}|null $prevConcurrency Previous concurrency level stats (same cpu/io, lower c) for saturation detection
     *
     * @return array{cpu_util: float, worker_util: float, verdict: string}
     */
    public static function verdict(array $s, ?array $prevConcurrency = null): array
    {
        $c = $s['concurrency'] ?? 1;

        // cpu_util: single CPU thread → divide by total_ms only (parallelism=1)
        $cpuUtil = $s['total_ms'] > 0
            ? round($s['cpu']['sum'] / $s['total_ms'], 4)
            : 0;

        // worker_util: logical worker slots → divide by total_ms × concurrency
        $totalSlotMs = $s['total_ms'] * $c;
        $workerUtil = $totalSlotMs > 0
            ? round($s['exec']['sum'] / $totalSlotMs, 4)
            : 0;

        if ($s['mode'] === 'sync') {
            return [
                'cpu_util' => $cpuUtil,
                'worker_util' => $workerUtil,
                'verdict' => 'BASELINE',
            ];
        }

        // ── Guards ──────────────────────────────────────────────────

        if ($s['jobs'] < 200) {
            return [
                'cpu_util' => $cpuUtil,
                'worker_util' => $workerUtil,
                'verdict' => 'N/A_SMALL_SAMPLE',
            ];
        }

        if ($s['exec']['p50'] < 0.2) {
            return [
                'cpu_util' => $cpuUtil,
                'worker_util' => $workerUtil,
                'verdict' => 'N/A_MICRO_BENCH',
            ];
        }

        // ── Async verdicts ──────────────────────────────────────────

        if ($cpuUtil > 0.85) {
            return [
                'cpu_util' => $cpuUtil,
                'worker_util' => $workerUtil,
                'verdict' => 'CPU_BOUND',
            ];
        }

        // ── Saturation: qw_p95 > 200ms OR sustained throughput regression ─────
        // Hysteresis: require throughput_drop >= 5% AND qw_p95_increase >= 20%
        // to avoid false positives from noise.
        $saturated = false;

        if ($s['queue_wait']['p95'] > 200) {
            $saturated = true;
        }

        if ($prevConcurrency !== null) {
            $prevJps = $prevConcurrency['jobs_per_sec'];
            $prevQw = $prevConcurrency['queue_wait']['p95'];
            $curJps = $s['jobs_per_sec'];
            $curQw = $s['queue_wait']['p95'];

            // Throughput dropped >= 5% AND queue wait increased >= 20%
            $throughputDrop = $prevJps > 0 ? ($prevJps - $curJps) / $prevJps : 0;
            $qwIncrease = $prevQw > 0 ? ($curQw - $prevQw) / $prevQw : 0;

            if ($throughputDrop >= 0.05 && $qwIncrease >= 0.20) {
                $saturated = true;
            }
        }

        if ($saturated) {
            return [
                'cpu_util' => $cpuUtil,
                'worker_util' => $workerUtil,
                'verdict' => 'SATURATED',
            ];
        }

        return [
            'cpu_util' => $cpuUtil,
            'worker_util' => $workerUtil,
            'verdict' => 'OK',
        ];
    }

    /**
     * @param list<array{mode: string, jobs: int, concurrency: null|int, total_ms: float, jobs_per_sec: float, queue_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, exec: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, cpu: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, io_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, e2e: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, rss_mb: null|float}> $allStats
     */
    public static function generate(array $allStats): void
    {
        $md = self::buildMarkdown($allStats);
        $csv = self::buildCsv($allStats);

        if (!is_dir(self::RESULTS_DIR)) {
            mkdir(self::RESULTS_DIR, 0755, true);
        }

        // Write to latest (backward compat)
        file_put_contents(self::RESULTS_DIR . '/latest.md', $md);
        file_put_contents(self::RESULTS_DIR . '/latest.csv', $csv);

        // Write to timestamped subdirectory
        $timestamp = date('Y-m-d_H-i-s');
        $runDir = self::RESULTS_DIR . '/' . $timestamp;

        if (!is_dir($runDir)) {
            mkdir($runDir, 0755, true);
        }

        file_put_contents($runDir . '/results.csv', $csv);
        file_put_contents($runDir . '/report.md', $md);

        echo $md;
        echo "\n✓ Archived to {$runDir}/\n";
    }

    /**
     * @param list<int> $ns
     *
     * @return array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}
     */
    private static function seriesStats(array $ns): array
    {
        if ($ns === []) {
            return ['p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0, 'min' => 0, 'max' => 0, 'sum' => 0];
        }

        sort($ns);

        /** @var non-empty-list<int> $ns */
        $count = count($ns);
        $toMs = static fn(int $v): float => round($v / 1_000_000, 3);

        $sum = 0;
        foreach ($ns as $v) {
            $sum += $v;
        }

        return [
            'p50' => $toMs(self::percentile($ns, 50)),
            'p95' => $toMs(self::percentile($ns, 95)),
            'p99' => $toMs(self::percentile($ns, 99)),
            'avg' => round(($sum / $count) / 1_000_000, 3),
            'min' => $toMs(reset($ns)),
            'max' => $toMs(end($ns)),
            'sum' => round($sum / 1_000_000, 2),
        ];
    }

    /**
     * @param list<array{mode: string, jobs: int, concurrency: null|int, total_ms: float, jobs_per_sec: float, queue_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, exec: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, cpu: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, io_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, e2e: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, rss_mb: null|float}> $allStats
     */
    private static function buildMarkdown(array $allStats): string
    {
        $md = "# Bench Results\n\n";

        // ── Summary ─────────────────────────────────────────────────────
        $md .= "## Summary\n\n";
        $md .= "| Mode | Jobs | Concurrency | Total (ms) | Jobs/s | MaxRSS (MB) |\n";
        $md .= "|------|------|-------------|------------|--------|-------------|\n";

        foreach ($allStats as $s) {
            $md .= sprintf(
                "| %s | %d | %s | %.2f | %.2f | %s |\n",
                $s['mode'],
                $s['jobs'],
                $s['concurrency'] !== null ? (string) $s['concurrency'] : '-',
                $s['total_ms'],
                $s['jobs_per_sec'],
                $s['rss_mb'] !== null ? number_format($s['rss_mb'], 2) : 'N/A',
            );
        }

        // ── Per-job latency tables ──────────────────────────────────────
        $series = [
            'exec' => 'Exec (service time)',
            'cpu' => 'CPU (compute only)',
            'io_wait' => 'IO Wait (sleep + resume delay)',
            'queue_wait' => 'Queue Wait (pickup latency)',
            'e2e' => 'E2E (enqueue → done)',
        ];

        foreach ($series as $key => $label) {
            $md .= "\n## {$label}\n\n";
            $md .= "| Mode | p50 (ms) | p95 (ms) | p99 (ms) | Avg (ms) | Min (ms) | Max (ms) |\n";
            $md .= "|------|----------|----------|----------|----------|----------|----------|\n";

            foreach ($allStats as $s) {
                $d = $s[$key];
                $md .= sprintf(
                    "| %s | %.3f | %.3f | %.3f | %.3f | %.3f | %.3f |\n",
                    $s['mode'],
                    $d['p50'],
                    $d['p95'],
                    $d['p99'],
                    $d['avg'],
                    $d['min'],
                    $d['max'],
                );
            }
        }

        // ── Health & Saturation ─────────────────────────────────────
        $md .= "\n## Health & Saturation\n\n";
        $md .= "| Mode | Concurrency | CPU Util | Worker Util | qw_p95/exec_p95 | Verdict |\n";
        $md .= "|------|-------------|----------|-------------|------------------|---------|\n";

        foreach ($allStats as $s) {
            $v = self::verdict($s);
            $c = $s['concurrency'] ?? 1;
            $qwExecRatio = $s['exec']['p95'] > 0
                ? round($s['queue_wait']['p95'] / $s['exec']['p95'], 2)
                : 0;

            $md .= sprintf(
                "| %s | %s | %.0f%% | %.0f%% | %.2f | %s |\n",
                $s['mode'],
                (string) $c,
                $v['cpu_util'] * 100,
                $v['worker_util'] * 100,
                $qwExecRatio,
                $v['verdict'],
            );
        }

        $md .= "\n> CPU Util = cpu_sum / total (single thread). Worker Util = exec_sum / (total × concurrency).\n";
        $md .= "> CPU_BOUND: cpu_util > 85%. SATURATED: qw_p95 > 200ms or throughput regression. OK: nominal.\n";

        // ── Comparatif ──────────────────────────────────────────────────
        if (count($allStats) === 2) {
            [$first, $second] = $allStats;
            $sync = $first['mode'] === 'sync' ? $first : $second;
            $async = $first['mode'] === 'async' ? $first : $second;

            $speedup = $async['total_ms'] > 0
                ? round($sync['total_ms'] / $async['total_ms'], 2)
                : 0;
            $gainPct = $sync['total_ms'] > 0
                ? round((1 - $async['total_ms'] / $sync['total_ms']) * 100, 2)
                : 0;

            $md .= "\n## Comparatif\n\n";
            $md .= "| Metric | Value |\n";
            $md .= "|--------|-------|\n";
            $md .= "| Speedup (sync_total / async_total) | {$speedup}x |\n";
            $md .= "| Gain % | {$gainPct}% |\n";
        }

        $md .= "\n_Generated: " . date('Y-m-d H:i:s T') . "_\n";

        return $md;
    }

    /**
     * @param list<array{mode: string, jobs: int, concurrency: null|int, total_ms: float, jobs_per_sec: float, queue_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, exec: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, cpu: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, io_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, e2e: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, rss_mb: null|float}> $allStats
     */
    private static function buildCsv(array $allStats): string
    {
        $columns = [
            'mode',
            'jobs',
            'concurrency',
            'total_ms',
            'jobs_per_sec',
            'exec_p50',
            'exec_p95',
            'exec_p99',
            'exec_avg',
            'exec_min',
            'exec_max',
            'exec_sum',
            'cpu_p50',
            'cpu_p95',
            'cpu_avg',
            'cpu_sum',
            'io_wait_p50',
            'io_wait_p95',
            'io_wait_avg',
            'io_wait_sum',
            'qw_p50',
            'qw_p95',
            'qw_p99',
            'qw_avg',
            'qw_sum',
            'e2e_p50',
            'e2e_p95',
            'e2e_p99',
            'e2e_avg',
            'e2e_sum',
            'rss_mb',
            'cpu_util',
            'worker_util',
            'verdict',
        ];

        $lines = [implode(',', $columns)];

        foreach ($allStats as $s) {
            $v = self::verdict($s);
            $line = [
                $s['mode'],
                (string) $s['jobs'],
                $s['concurrency'] !== null ? (string) $s['concurrency'] : '',
                (string) $s['total_ms'],
                (string) $s['jobs_per_sec'],
                (string) $s['exec']['p50'],
                (string) $s['exec']['p95'],
                (string) $s['exec']['p99'],
                (string) $s['exec']['avg'],
                (string) $s['exec']['min'],
                (string) $s['exec']['max'],
                (string) $s['exec']['sum'],
                (string) $s['cpu']['p50'],
                (string) $s['cpu']['p95'],
                (string) $s['cpu']['avg'],
                (string) $s['cpu']['sum'],
                (string) $s['io_wait']['p50'],
                (string) $s['io_wait']['p95'],
                (string) $s['io_wait']['avg'],
                (string) $s['io_wait']['sum'],
                (string) $s['queue_wait']['p50'],
                (string) $s['queue_wait']['p95'],
                (string) $s['queue_wait']['p99'],
                (string) $s['queue_wait']['avg'],
                (string) $s['queue_wait']['sum'],
                (string) $s['e2e']['p50'],
                (string) $s['e2e']['p95'],
                (string) $s['e2e']['p99'],
                (string) $s['e2e']['avg'],
                (string) $s['e2e']['sum'],
                $s['rss_mb'] !== null ? (string) $s['rss_mb'] : '',
                (string) $v['cpu_util'],
                (string) $v['worker_util'],
                $v['verdict'],
            ];
            $lines[] = implode(',', $line);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param non-empty-list<int> $sorted
     */
    private static function percentile(array $sorted, int $p): int
    {
        $count = count($sorted);

        $index = (int) ceil(($p / 100) * $count) - 1;
        $index = max(0, min($index, $count - 1));

        assert(isset($sorted[$index]));

        return $sorted[$index];
    }
}
