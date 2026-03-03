<?php

declare(strict_types=1);

namespace Octo\Bench;

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

    /** @var int[] */
    private array $concurrencies;

    /** @var int[] */
    private array $ioValues;

    /** @var int[] */
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

        $csvRows = [];

        foreach ($this->cpuValues as $cpu) {
            foreach ($this->ioValues as $io) {
                // ── Sync baseline for this (cpu, io) pair ───────────
                $current++;
                $label = "cpu={$cpu} io={$io}ms";
                echo "[{$current}/{$total}] SYNC {$label}...\n";

                $syncResult = $syncRunner->run($this->jobs, $cpu, $io, $this->jsonKb);
                $syncResult['mode'] = 'sync';
                $syncResult['concurrency'] = null;
                $syncStats = Report::stats($syncResult);

                $csvRows[] = self::toCsvRow($syncStats, $cpu, $io);

                // ── Async runs at each concurrency level ────────────
                foreach ($this->concurrencies as $c) {
                    $current++;
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

        // ── Write CSV ───────────────────────────────────────────────
        if (!is_dir(self::RESULTS_DIR)) {
            mkdir(self::RESULTS_DIR, 0755, true);
        }

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
        ];

        $lines = [implode(',', $columns)];
        foreach ($csvRows as $row) {
            $values = [];
            foreach ($columns as $col) {
                $v = $row[$col] ?? '';
                $values[] = $v === null ? '' : (string) $v;
            }
            $lines[] = implode(',', $values);
        }

        $csvPath = self::RESULTS_DIR . '/matrix.csv';
        file_put_contents($csvPath, implode("\n", $lines) . "\n");

        echo "\n✓ Matrix complete: {$total} runs\n";
        echo "✓ Results written to {$csvPath}\n";
        echo "\nTip: import matrix.csv into a spreadsheet or use:\n";
        echo "  column -t -s, bench/results/matrix.csv | head -30\n";
    }

    /**
     * @param array<string, mixed> $stats
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
        ];
    }
}
