<?php

declare(strict_types=1);

namespace Octo\Bench;

use function count;

/**
 * Matrix runner: executes multiple bench combinations and produces
 * an aggregated CSV for scaling analysis.
 *
 * Default matrix axes:
 *   concurrency: [1, 5, 10, 20, 50, 100]
 *   io-ms:       [0, 2, 5, 10]
 *   cpu:         [0, 1000, 5000]
 *
 * For each (cpu, io) pair: one sync baseline + N async runs (per concurrency).
 * Output: bench/results/matrix.csv + console progress.
 */
final class Matrix
{
    private const RESULTS_DIR = __DIR__ . '/results';

    /** @var list<int> */
    private array $concurrencies;

    /** @var list<int> */
    private array $ioValues;

    /** @var list<int> */
    private array $cpuValues;

    public function __construct(
        private readonly int $jobs = 200,
        private readonly int $jsonKb = 8,
    ) {
        $this->concurrencies = [1, 5, 10, 20, 50, 100];
        $this->ioValues = [0, 2, 5, 10];
        $this->cpuValues = [0, 1000, 5000];
    }

    public function run(): void
    {
        $syncRunner = new RunnerSync();
        $asyncRunner = new RunnerAsync();

        $asyncCombinations = count($this->concurrencies)
            * count($this->ioValues)
            * count($this->cpuValues);
        $syncCombinations = count($this->ioValues) * count($this->cpuValues);
        $total = $syncCombinations + $asyncCombinations;
        $current = 0;

        $startWall = hrtime(true);
        $csvRows = [];

        foreach ($this->cpuValues as $cpu) {
            foreach ($this->ioValues as $io) {
                // ── Sync baseline for this (cpu, io) pair ───────────
                ++$current;
                $label = "cpu={$cpu} io={$io}ms";
                echo "[{$current}/{$total}] SYNC {$label}...\n";

                $syncResult = $syncRunner->run($this->jobs, $cpu, $io, $this->jsonKb);
                $syncResult['mode'] = 'sync';
                $syncResult['concurrency'] = null;
                $syncStats = Report::stats($syncResult);

                $csvRows[] = self::toCsvRow($syncStats, $cpu, $io);

                // ── Async runs at each concurrency level ────────────
                foreach ($this->concurrencies as $c) {
                    ++$current;
                    echo "[{$current}/{$total}] ASYNC {$label} c={$c}...\n";

                    $asyncResult = $asyncRunner->run(
                        $this->jobs,
                        $c,
                        $cpu,
                        $io,
                        $this->jsonKb,
                    );
                    $asyncResult['mode'] = 'async';
                    $asyncResult['concurrency'] = $c;
                    $asyncStats = Report::stats($asyncResult);

                    $csvRows[] = self::toCsvRow($asyncStats, $cpu, $io);
                }
            }
        }

        $wallMs = round((hrtime(true) - $startWall) / 1_000_000, 0);

        // ── Enrich with baseline normalization ──────────────────────
        $csvRows = self::enrichWithBaseline($csvRows);

        // ── Create timestamped output directory ─────────────────────
        $timestamp = date('Y-m-d_H-i-s');
        $runDir = self::RESULTS_DIR . '/' . $timestamp;

        if (!is_dir($runDir)) {
            mkdir($runDir, 0755, true);
        }

        // ── Write CSV ───────────────────────────────────────────────
        $csvContent = self::buildMatrixCsv($csvRows);
        file_put_contents($runDir . '/matrix.csv', $csvContent);

        // Also write to results/matrix.csv for backward compat
        if (!is_dir(self::RESULTS_DIR)) {
            mkdir(self::RESULTS_DIR, 0755, true);
        }
        file_put_contents(self::RESULTS_DIR . '/matrix.csv', $csvContent);

        // ── Generate SVG graphs ─────────────────────────────────────
        $parsedRows = GraphGenerator::parseCsv($runDir . '/matrix.csv');
        $graphFiles = GraphGenerator::generateAll($parsedRows, $runDir);

        // ── Write styled Markdown report ────────────────────────────
        $sweetSpots = self::findSweetSpots($csvRows);
        $md = self::buildMatrixMarkdown($csvRows, $graphFiles, $total, $wallMs, $sweetSpots);
        file_put_contents($runDir . '/report.md', $md);

        echo "\n✓ Matrix complete: {$total} runs in " . number_format($wallMs / 1000, 1) . "s\n";
        echo "✓ Results written to {$runDir}/\n";
        echo "  ├── matrix.csv\n";
        echo "  ├── report.md\n";

        foreach ($graphFiles as $f) {
            echo '  ├── ' . basename($f) . "\n";
        }

        echo "\nTip: open report.md for the full analysis with graphs.\n";
    }

    /**
     * @param list<array<string, null|float|int|string>> $csvRows
     */
    private static function buildMatrixCsv(array $csvRows): string
    {
        $columns = [
            'mode',
            'jobs',
            'cpu',
            'io_ms',
            'json_kb',
            'concurrency',
            'total_ms',
            'jobs_per_sec',
            'exec_p50',
            'exec_p95',
            'exec_p99',
            'exec_avg',
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
            'qw_avg',
            'qw_sum',
            'e2e_p50',
            'e2e_p95',
            'e2e_p99',
            'e2e_avg',
            'rss_mb',
            'cpu_util',
            'worker_util',
            'verdict',
            'speedup_vs_sync',
            'latency_penalty_p95',
        ];

        $lines = [implode(',', $columns)];
        foreach ($csvRows as $row) {
            $values = [];
            foreach ($columns as $col) {
                $values[] = (string) ($row[$col] ?? '');
            }
            $lines[] = implode(',', $values);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Build a styled Markdown report with OctoPHP branding and embedded graph links.
     *
     * @param list<array<string, null|float|int|string>> $csvRows
     * @param list<string> $graphFiles
     */
    private static function buildMatrixMarkdown(array $csvRows, array $graphFiles, int $totalRuns, float $wallMs, array $sweetSpots = []): string
    {
        $date = date('Y-m-d H:i:s T');
        $wallSec = number_format($wallMs / 1000, 1);

        // Count unique configs
        $asyncRows = array_filter($csvRows, static fn(array $r) => $r['mode'] === 'async');
        $syncRows = array_filter($csvRows, static fn(array $r) => $r['mode'] === 'sync');

        // Find best throughput
        $bestThroughput = 0;
        $bestConfig = '';
        foreach ($asyncRows as $r) {
            $jps = (float) ($r['jobs_per_sec'] ?? 0);
            if ($jps > $bestThroughput) {
                $bestThroughput = $jps;
                $bestConfig = sprintf(
                    'cpu=%s io=%sms c=%s',
                    $r['cpu'] ?? '?',
                    $r['io_ms'] ?? '?',
                    $r['concurrency'] ?? '?',
                );
            }
        }

        // Count verdicts
        $verdicts = [];
        foreach ($asyncRows as $r) {
            $v = $r['verdict'] ?? 'UNKNOWN';
            $verdicts[$v] = ($verdicts[$v] ?? 0) + 1;
        }

        $asyncCount = count(array_values($asyncRows));
        $syncCount = count(array_values($syncRows));

        $md = "# 🐙 OctoPHP — Matrix Bench Report\n\n";
        $md .= "> Async PHP Platform — Concurrency × IO × CPU sweep analysis\n\n";
        $md .= "---\n\n";
        $md .= "## Run Info\n\n";
        $md .= "| Parameter | Value |\n";
        $md .= "| --------- | ----- |\n";
        $md .= "| Date | {$date} |\n";
        $md .= "| Total runs | {$totalRuns} ({$syncCount} sync + {$asyncCount} async) |\n";
        $md .= "| Wall time | {$wallSec}s |\n";
        $md .= "| Best throughput | " . number_format($bestThroughput, 1) . " jobs/s ({$bestConfig}) |\n\n";

        // Verdicts summary
        $md .= "## Verdicts Summary\n\n";
        $md .= "| Verdict | Count |\n";
        $md .= "| ------- | ----- |\n";
        foreach ($verdicts as $v => $count) {
            $md .= "| {$v} | {$count} |\n";
        }
        $md .= "\n";

        // Graphs
        if ($graphFiles !== []) {
            $md .= "## Graphs\n\n";

            $graphLabels = [
                'heatmap-throughput.svg' => '### Heatmap — Concurrency × IO → Throughput',
                'throughput.svg' => '### Throughput vs Concurrency',
                'saturation.svg' => '### Saturation — Queue Wait p95',
                'cpu-utilization.svg' => '### CPU Utilization',
            ];

            foreach ($graphFiles as $f) {
                $basename = basename($f);
                $label = $graphLabels[$basename] ?? "### {$basename}";
                $md .= "{$label}\n\n";
                $md .= "![{$basename}](./{$basename})\n\n";
            }
        }

        // Sweet spots
        if ($sweetSpots !== []) {
            $md .= "## 🎯 Recommended Concurrency (Sweet Spots)\n\n";
            $md .= "> Best throughput under latency constraint (qw_p95 < 50ms); if none under threshold, first stable diminishing-returns point.\n\n";
            $md .= "| CPU | IO (ms) | Best c | Jobs/s | Speedup vs sync | qw_p95 (ms) | Verdict |\n";
            $md .= "| --- | ------- | ------ | ------ | --------------- | ----------- | ------- |\n";

            foreach ($sweetSpots as $sp) {
                $md .= sprintf(
                    "| %s | %s | %s | %s | %sx | %s | %s |\n",
                    $sp['cpu'],
                    $sp['io_ms'],
                    $sp['best_c'],
                    number_format((float) $sp['jobs_per_sec'], 1),
                    $sp['speedup'] !== '' ? $sp['speedup'] : '-',
                    $sp['qw_p95'],
                    $sp['verdict'],
                );
            }
            $md .= "\n";
            $md .= "> ⚠️ Micro-bench (io=0ms cpu=0) = mesure d'overhead runtime, pas représentatif de la prod.\n";
            $md .= "> Sélection : meilleur throughput sous qw_p95 < 50ms ; sinon premier point à rendement marginal < 5%.\n\n";
        }

        // Normalized vs baseline table (async only, with speedup + latency penalty)
        $asyncWithSpeedup = array_filter(
            $csvRows,
            static fn(array $r) => $r['mode'] === 'async' && $r['speedup_vs_sync'] !== '',
        );

        if ($asyncWithSpeedup !== []) {
            $md .= "## Normalized vs Sync Baseline\n\n";
            $md .= "| CPU | IO (ms) | c | Jobs/s | Speedup | Latency penalty (e2e_p95) | Verdict |\n";
            $md .= "| --- | ------- | - | ------ | ------- | ------------------------- | ------- |\n";

            foreach ($asyncWithSpeedup as $r) {
                $speedup = (float) $r['speedup_vs_sync'];
                $penalty = (float) $r['latency_penalty_p95'];
                $md .= sprintf(
                    "| %s | %s | %s | %s | %sx | %sx | %s |\n",
                    $r['cpu'] ?? '',
                    $r['io_ms'] ?? '',
                    $r['concurrency'] ?? '',
                    number_format((float) ($r['jobs_per_sec'] ?? 0), 1),
                    number_format($speedup, 2),
                    number_format($penalty, 2),
                    $r['verdict'] ?? '',
                );
            }
            $md .= "\n";
        }

        // Top performers table
        $md .= "## Top 10 Async Throughput\n\n";
        $md .= "| CPU | IO (ms) | Concurrency | Jobs/s | CPU Util | Worker Util | Verdict |\n";
        $md .= "| --- | ------- | ----------- | ------ | -------- | ----------- | ------- |\n";

        $asyncSorted = array_values(array_filter($csvRows, static fn(array $r) => $r['mode'] === 'async'));
        usort($asyncSorted, static fn(array $a, array $b) => (float) ($b['jobs_per_sec'] ?? 0) <=> (float) ($a['jobs_per_sec'] ?? 0));

        $top = array_slice($asyncSorted, 0, 10);
        foreach ($top as $r) {
            $cpuPct = round((float) ($r['cpu_util'] ?? 0) * 100);
            $workerPct = round((float) ($r['worker_util'] ?? 0) * 100);
            $md .= sprintf(
                "| %s | %s | %s | %s | %s%% | %s%% | %s |\n",
                $r['cpu'] ?? '',
                $r['io_ms'] ?? '',
                $r['concurrency'] ?? '',
                number_format((float) ($r['jobs_per_sec'] ?? 0), 1),
                $cpuPct,
                $workerPct,
                $r['verdict'] ?? '',
            );
        }

        $md .= "\n---\n\n";
        $md .= "_Generated by OctoPHP Bench Suite — {$date}_\n";

        return $md;
    }

    /**
     * @param array{mode: string, jobs: int, concurrency: null|int, total_ms: float, jobs_per_sec: float, queue_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, exec: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, cpu: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, io_wait: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, e2e: array{p50: float, p95: float, p99: float, avg: float, min: float, max: float, sum: float}, rss_mb: null|float} $stats
     *
     * @return array<string, null|float|int|string>
     */
    private static function toCsvRow(array $stats, int $cpu, int $io): array
    {
        $v = Report::verdict($stats);

        return [
            'mode' => $stats['mode'],
            'jobs' => $stats['jobs'],
            'cpu' => $cpu,
            'io_ms' => $io,
            'json_kb' => 8,
            'concurrency' => $stats['concurrency'] ?? '',
            'total_ms' => $stats['total_ms'],
            'jobs_per_sec' => $stats['jobs_per_sec'],
            'exec_p50' => $stats['exec']['p50'],
            'exec_p95' => $stats['exec']['p95'],
            'exec_p99' => $stats['exec']['p99'],
            'exec_avg' => $stats['exec']['avg'],
            'exec_sum' => $stats['exec']['sum'],
            'cpu_p50' => $stats['cpu']['p50'],
            'cpu_p95' => $stats['cpu']['p95'],
            'cpu_avg' => $stats['cpu']['avg'],
            'cpu_sum' => $stats['cpu']['sum'],
            'io_wait_p50' => $stats['io_wait']['p50'],
            'io_wait_p95' => $stats['io_wait']['p95'],
            'io_wait_avg' => $stats['io_wait']['avg'],
            'io_wait_sum' => $stats['io_wait']['sum'],
            'qw_p50' => $stats['queue_wait']['p50'],
            'qw_p95' => $stats['queue_wait']['p95'],
            'qw_avg' => $stats['queue_wait']['avg'],
            'qw_sum' => $stats['queue_wait']['sum'],
            'e2e_p50' => $stats['e2e']['p50'],
            'e2e_p95' => $stats['e2e']['p95'],
            'e2e_p99' => $stats['e2e']['p99'],
            'e2e_avg' => $stats['e2e']['avg'],
            'rss_mb' => $stats['rss_mb'],
            'cpu_util' => $v['cpu_util'],
            'worker_util' => $v['worker_util'],
            'verdict' => $v['verdict'],
            // Placeholders — enriched post-collection by enrichWithBaseline()
            'speedup_vs_sync' => '',
            'latency_penalty_p95' => '',
        ];
    }

    /**
     * Enrich async rows with speedup_vs_sync and latency_penalty_p95 relative to sync baseline.
     *
     * @param list<array<string, null|float|int|string>> $csvRows
     *
     * @return list<array<string, null|float|int|string>>
     */
    private static function enrichWithBaseline(array $csvRows): array
    {
        // Index sync baselines by "cpu_io" key
        $syncIndex = [];
        foreach ($csvRows as $row) {
            if ($row['mode'] !== 'sync') {
                continue;
            }
            $key = $row['cpu'] . '_' . $row['io_ms'];
            $syncIndex[$key] = $row;
        }

        foreach ($csvRows as &$row) {
            if ($row['mode'] !== 'async') {
                continue;
            }

            $key = $row['cpu'] . '_' . $row['io_ms'];
            $sync = $syncIndex[$key] ?? null;

            if ($sync === null) {
                continue;
            }

            $syncJps = (float) ($sync['jobs_per_sec'] ?? 0);
            $asyncJps = (float) ($row['jobs_per_sec'] ?? 0);
            $row['speedup_vs_sync'] = $syncJps > 0
                ? round($asyncJps / $syncJps, 2)
                : '';

            $syncE2e = (float) ($sync['e2e_p95'] ?? 0);
            $asyncE2e = (float) ($row['e2e_p95'] ?? 0);
            $row['latency_penalty_p95'] = $syncE2e > 0
                ? round($asyncE2e / $syncE2e, 2)
                : '';
        }
        unset($row);

        return $csvRows;
    }

    /**
     * Find the sweet spot concurrency for each (cpu, io_ms) combination.
     *
     * Strategy: best jobs_per_sec where qw_p95 < threshold (default 50ms).
     * Fallback: first c where marginal gain < 5%.
     *
     * @param list<array<string, null|float|int|string>> $csvRows
     *
     * @return list<array{cpu: string, io_ms: string, best_c: string, jobs_per_sec: string, speedup: string, qw_p95: string, verdict: string}>
     */
    private static function findSweetSpots(array $csvRows, float $qwThreshold = 50.0): array
    {
        // Group async rows by (cpu, io_ms)
        $groups = [];
        foreach ($csvRows as $row) {
            if ($row['mode'] !== 'async') {
                continue;
            }
            $key = $row['cpu'] . '_' . $row['io_ms'];
            $groups[$key][] = $row;
        }

        $spots = [];

        foreach ($groups as $rows) {
            // Sort by concurrency ascending
            usort($rows, static fn(array $a, array $b) => (int) ($a['concurrency'] ?? 0) <=> (int) ($b['concurrency'] ?? 0));

            // Strategy 1: best throughput under qw_p95 threshold
            $bestUnderThreshold = null;
            foreach ($rows as $r) {
                $qw = (float) ($r['qw_p95'] ?? 0);
                $jps = (float) ($r['jobs_per_sec'] ?? 0);
                if ($qw <= $qwThreshold && ($bestUnderThreshold === null || $jps > (float) ($bestUnderThreshold['jobs_per_sec'] ?? 0))) {
                    $bestUnderThreshold = $r;
                }
            }

            // Strategy 2 fallback: first c where marginal gain < 5%
            $marginalBest = null;
            $prevJps = 0.0;
            foreach ($rows as $r) {
                $jps = (float) ($r['jobs_per_sec'] ?? 0);
                if ($prevJps > 0 && $jps > 0) {
                    $marginalGain = ($jps - $prevJps) / $prevJps;
                    if ($marginalGain < 0.05 && $marginalBest === null) {
                        $marginalBest = $r;
                    }
                }
                $prevJps = $jps;
            }

            $best = $bestUnderThreshold ?? $marginalBest ?? $rows[0] ?? null;

            if ($best === null) {
                continue;
            }

            $spots[] = [
                'cpu' => (string) ($best['cpu'] ?? ''),
                'io_ms' => (string) ($best['io_ms'] ?? ''),
                'best_c' => (string) ($best['concurrency'] ?? ''),
                'jobs_per_sec' => (string) ($best['jobs_per_sec'] ?? ''),
                'speedup' => (string) ($best['speedup_vs_sync'] ?? ''),
                'qw_p95' => (string) ($best['qw_p95'] ?? ''),
                'verdict' => (string) ($best['verdict'] ?? ''),
            ];
        }

        return $spots;
    }
}
