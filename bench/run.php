<?php

declare(strict_types=1);

/**
 * Bench CLI entrypoint.
 *
 * Usage:
 *   php bench/run.php --mode=both --jobs=2000 --concurrency=200 --cpu=20000 --io-ms=5 --json-kb=8
 *   php bench/run.php --mode=matrix --jobs=500
 */

require_once __DIR__ . '/Job.php';
require_once __DIR__ . '/RunnerSync.php';
require_once __DIR__ . '/RunnerAsync.php';
require_once __DIR__ . '/Report.php';
require_once __DIR__ . '/Matrix.php';

use Octo\Bench\Matrix;
use Octo\Bench\Report;
use Octo\Bench\RunnerAsync;
use Octo\Bench\RunnerSync;

// ── Parse CLI args ──────────────────────────────────────────────────────────

$options = getopt('', [
    'mode:',
    'jobs:',
    'concurrency:',
    'cpu:',
    'io-ms:',
    'json-kb:',
    'yield-every:',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
Async PHP Platform — Bench

Options:
  --mode=sync|async|both|matrix  Execution mode (default: both)
  --jobs=N                       Number of jobs per run (default: 2000, matrix: 500)
  --concurrency=N                Max concurrent workers, async only (default: 200)
  --cpu=N                        SHA-256 iterations per job (default: 20000)
  --io-ms=N                      Simulated IO sleep in ms (default: 5)
  --json-kb=N                    JSON payload size in KB (default: 8)
  --yield-every=N                Cooperative yield every N CPU iterations, async only (default: 0 = disabled)
  --help                         Show this help

Modes:
  sync    — Sequential baseline only
  async   — Worker-pool coroutines only
  both    — Sync + async, side-by-side comparison
  matrix  — Sweep across concurrency × io-ms × cpu combinations (CSV output)

Examples:
  php bench/run.php --mode=both --jobs=500 --concurrency=50 --cpu=5000 --io-ms=2 --json-kb=4
  php bench/run.php --mode=matrix --jobs=300
  php bench/run.php --mode=async --jobs=10000 --concurrency=500

HELP;
    exit(0);
}

$mode = $options['mode'] ?? (getenv('BENCH_MODE') !== false ? getenv('BENCH_MODE') : '') ?: 'both';

$jobs = array_key_exists('jobs', $options)
    ? (int) $options['jobs']
    : (getenv('JOBS') !== false ? (int) getenv('JOBS') : ($mode === 'matrix' ? 500 : 2000));

$concurrency = array_key_exists('concurrency', $options)
    ? (int) $options['concurrency']
    : (getenv('CONCURRENCY') !== false ? (int) getenv('CONCURRENCY') : 200);

$cpuN = array_key_exists('cpu', $options)
    ? (int) $options['cpu']
    : (getenv('CPU_N') !== false ? (int) getenv('CPU_N') : 20000);

$ioMs = array_key_exists('io-ms', $options)
    ? (int) $options['io-ms']
    : (getenv('IO_MS') !== false ? (int) getenv('IO_MS') : 5);

$jsonKb = array_key_exists('json-kb', $options)
    ? (int) $options['json-kb']
    : (getenv('JSON_KB') !== false ? (int) getenv('JSON_KB') : 8);

$yieldEvery = array_key_exists('yield-every', $options)
    ? (int) $options['yield-every']
    : (getenv('YIELD_EVERY') !== false ? (int) getenv('YIELD_EVERY') : 0);

// ── Validate ────────────────────────────────────────────────────────────────

$validModes = ['sync', 'async', 'both', 'matrix'];
if (!in_array($mode, $validModes, true)) {
    fwrite(STDERR, "Error: --mode must be one of: " . implode(', ', $validModes) . "\n");
    exit(1);
}

if ($jobs <= 0) {
    fwrite(STDERR, "Error: --jobs must be > 0\n");
    exit(1);
}

if ($concurrency <= 0 && $mode !== 'sync') {
    fwrite(STDERR, "Error: --concurrency must be > 0\n");
    exit(1);
}

if ($cpuN < 0 || $ioMs < 0 || $jsonKb <= 0) {
    fwrite(STDERR, "Error: --cpu >= 0, --io-ms >= 0, --json-kb > 0\n");
    exit(1);
}

// ── Banner ──────────────────────────────────────────────────────────────────

echo "╔══════════════════════════════════════════════════╗\n";
echo "║       Async PHP Platform — Bench Suite           ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

// ── Matrix mode ─────────────────────────────────────────────────────────────

if ($mode === 'matrix') {
    echo "Config: mode=matrix jobs={$jobs} json-kb={$jsonKb}\n";
    echo "Sweeping: concurrency=[1,5,10,20,50,100] × io-ms=[0,2,5,10] × cpu=[0,1000,5000]\n\n";

    $matrix = new Matrix(jobs: $jobs, jsonKb: $jsonKb);
    $matrix->run();
    exit(0);
}

// ── Single/both mode ────────────────────────────────────────────────────────

echo "Config: mode={$mode} jobs={$jobs} concurrency={$concurrency} cpu={$cpuN} io-ms={$ioMs} json-kb={$jsonKb} yield-every={$yieldEvery}\n\n";

$allStats = [];

if ($mode === 'sync' || $mode === 'both') {
    echo "▶ Running SYNC...\n";
    $syncRunner = new RunnerSync();
    $syncResult = $syncRunner->run($jobs, $cpuN, $ioMs, $jsonKb);
    $syncResult['mode'] = 'sync';
    $syncResult['concurrency'] = null;
    $syncStats = Report::stats($syncResult);
    $allStats[] = $syncStats;
    echo "  ✓ Sync done in {$syncStats['total_ms']} ms ({$syncStats['jobs_per_sec']} jobs/s)\n\n";
}

if ($mode === 'async' || $mode === 'both') {
    echo "▶ Running ASYNC (concurrency={$concurrency})...\n";
    $asyncRunner = new RunnerAsync();
    $asyncResult = $asyncRunner->run($jobs, $concurrency, $cpuN, $ioMs, $jsonKb, $yieldEvery);
    $asyncResult['mode'] = 'async';
    $asyncResult['concurrency'] = $concurrency;
    $asyncStats = Report::stats($asyncResult);
    $allStats[] = $asyncStats;
    echo "  ✓ Async done in {$asyncStats['total_ms']} ms ({$asyncStats['jobs_per_sec']} jobs/s)\n\n";
}

// ── Report ──────────────────────────────────────────────────────────────────

echo "─────────────────────────────────────────────────────\n\n";
Report::generate($allStats);

echo "\n✓ Results written to bench/results/latest.md and bench/results/latest.csv\n";
