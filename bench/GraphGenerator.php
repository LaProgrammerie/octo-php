<?php

declare(strict_types=1);

namespace Octo\Bench;

/**
 * Generates SVG charts from bench CSV data.
 *
 * Charts produced:
 *   1. Heatmap: concurrency × IO → throughput (jobs/s)
 *   2. Throughput: jobs/s vs concurrency (per cpu/io combo)
 *   3. Saturation: qw_p95 vs concurrency
 *   4. CPU Utilization: cpu_util vs concurrency
 *
 * No external dependencies — pure SVG output.
 */
final class GraphGenerator
{
    private const COLORS = [
        '#2563eb',
        '#dc2626',
        '#16a34a',
        '#ea580c',
        '#7c3aed',
        '#0891b2',
        '#ca8a04',
        '#be185d',
    ];

    /**
     * @param list<array<string, string>> $rows Parsed CSV rows (assoc arrays)
     * @param string $outputDir Directory to write SVG files
     *
     * @return list<string> List of generated file paths
     */
    public static function generateAll(array $rows, string $outputDir): array
    {
        if ($rows === []) {
            return [];
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $asyncRows = array_values(array_filter($rows, static fn (array $r) => $r['mode'] === 'async'));

        if ($asyncRows === []) {
            return [];
        }

        $files = [];

        $files[] = self::heatmap($asyncRows, $outputDir . '/heatmap-throughput.svg');
        $files[] = self::lineChart(
            $asyncRows,
            'jobs_per_sec',
            'Throughput (jobs/s) vs Concurrency',
            'jobs/s',
            $outputDir . '/throughput.svg',
        );
        $files[] = self::lineChart(
            $asyncRows,
            'qw_p95',
            'Saturation — Queue Wait p95 (ms) vs Concurrency',
            'qw_p95 (ms)',
            $outputDir . '/saturation.svg',
        );
        $files[] = self::lineChart(
            $asyncRows,
            'cpu_util',
            'CPU Utilization vs Concurrency',
            'cpu_util',
            $outputDir . '/cpu-utilization.svg',
        );

        return $files;
    }

    /**
     * Parse a matrix CSV file into associative arrays.
     *
     * @return list<array<string, string>>
     */
    public static function parseCsv(string $csvPath): array
    {
        $lines = file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || count($lines) < 2) {
            return [];
        }

        $headers = str_getcsv($lines[0], ',', '"', '');
        $rows = [];

        for ($i = 1, $count = count($lines); $i < $count; ++$i) {
            $values = str_getcsv($lines[$i], ',', '"', '');
            if (count($values) === count($headers)) {
                $rows[] = array_combine($headers, $values);
            }
        }

        return $rows;
    }

    /**
     * Heatmap: concurrency (x) × io_ms (y) → throughput color.
     * Fixed cpu=5000 (highest non-trivial CPU load in matrix).
     */
    private static function heatmap(array $rows, string $path): string
    {
        // Filter to a single cpu value for a clean heatmap
        $cpuTarget = '5000';
        $filtered = array_values(array_filter(
            $rows,
            static fn (array $r) => ($r['cpu'] ?? '') === $cpuTarget,
        ));

        if ($filtered === []) {
            // Fallback: use whatever cpu values exist, pick the most common
            $cpuCounts = [];
            foreach ($rows as $r) {
                $c = $r['cpu'] ?? '0';
                $cpuCounts[$c] = ($cpuCounts[$c] ?? 0) + 1;
            }
            arsort($cpuCounts);
            $cpuTarget = (string) array_key_first($cpuCounts);
            $filtered = array_values(array_filter(
                $rows,
                static fn (array $r) => ($r['cpu'] ?? '') === $cpuTarget,
            ));
        }

        // Extract unique axes
        $concurrencies = [];
        $ioValues = [];
        $data = [];

        foreach ($filtered as $r) {
            $c = (int) ($r['concurrency'] ?? 0);
            $io = (int) ($r['io_ms'] ?? 0);
            $val = (float) ($r['jobs_per_sec'] ?? 0);

            $concurrencies[$c] = true;
            $ioValues[$io] = true;
            $data["{$c}_{$io}"] = $val;
        }

        ksort($concurrencies);
        ksort($ioValues);
        $concurrencies = array_keys($concurrencies);
        $ioValues = array_keys($ioValues);

        if ($concurrencies === [] || $ioValues === []) {
            file_put_contents($path, self::emptySvg('No data for heatmap'));

            return $path;
        }

        $allVals = array_values($data);
        $minVal = min($allVals);
        $maxVal = max($allVals);

        // SVG dimensions
        $cellW = 80;
        $cellH = 50;
        $marginLeft = 80;
        $marginTop = 60;
        $marginRight = 120; // legend
        $marginBottom = 50;
        $cols = count($concurrencies);
        $rowCount = count($ioValues);
        $w = $marginLeft + $cols * $cellW + $marginRight;
        $h = $marginTop + $rowCount * $cellH + $marginBottom;

        $svg = self::svgHeader($w, $h);
        $svg .= self::svgTitle('Heatmap: Throughput (jobs/s) — cpu=' . $cpuTarget, $w);

        // Y-axis label
        foreach ($ioValues as $yi => $io) {
            $y = $marginTop + $yi * $cellH + $cellH / 2 + 5;
            $svg .= "<text x=\"" . ($marginLeft - 10) . "\" y=\"{$y}\" text-anchor=\"end\" font-size=\"12\" fill=\"#374151\">{$io}ms</text>\n";
        }

        // X-axis label
        foreach ($concurrencies as $xi => $c) {
            $x = $marginLeft + $xi * $cellW + $cellW / 2;
            $y = $marginTop + $rowCount * $cellH + 20;
            $svg .= "<text x=\"{$x}\" y=\"{$y}\" text-anchor=\"middle\" font-size=\"12\" fill=\"#374151\">c={$c}</text>\n";
        }

        // Axis titles
        $svg .= "<text x=\"" . ($marginLeft + $cols * $cellW / 2) . "\" y=\"" . ($h - 5) . "\" text-anchor=\"middle\" font-size=\"13\" fill=\"#111827\" font-weight=\"600\">Concurrency</text>\n";
        $svg .= "<text x=\"15\" y=\"" . ($marginTop + $rowCount * $cellH / 2) . "\" text-anchor=\"middle\" font-size=\"13\" fill=\"#111827\" font-weight=\"600\" transform=\"rotate(-90, 15, " . ($marginTop + $rowCount * $cellH / 2) . ")\">IO (ms)</text>\n";

        // Cells
        foreach ($ioValues as $yi => $io) {
            foreach ($concurrencies as $xi => $c) {
                $key = "{$c}_{$io}";
                $val = $data[$key] ?? 0;
                $color = self::heatColor($val, $minVal, $maxVal);
                $x = $marginLeft + $xi * $cellW;
                $y = $marginTop + $yi * $cellH;
                $textColor = self::contrastColor($color);

                $svg .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$cellW}\" height=\"{$cellH}\" fill=\"{$color}\" stroke=\"#fff\" stroke-width=\"2\" rx=\"4\"/>\n";
                $svg .= "<text x=\"" . ($x + $cellW / 2) . "\" y=\"" . ($y + $cellH / 2 + 5) . "\" text-anchor=\"middle\" font-size=\"11\" font-weight=\"600\" fill=\"{$textColor}\">" . number_format($val, 0) . "</text>\n";
            }
        }

        // Legend
        $legendX = $marginLeft + $cols * $cellW + 15;
        $legendH = $rowCount * $cellH;
        $steps = 10;
        $stepH = $legendH / $steps;

        for ($i = 0; $i < $steps; ++$i) {
            $ratio = 1 - $i / ($steps - 1);
            $val = $minVal + $ratio * ($maxVal - $minVal);
            $color = self::heatColor($val, $minVal, $maxVal);
            $y = $marginTop + $i * $stepH;
            $svg .= "<rect x=\"{$legendX}\" y=\"{$y}\" width=\"20\" height=\"{$stepH}\" fill=\"{$color}\"/>\n";
            $svg .= "<text x=\"" . ($legendX + 25) . "\" y=\"" . ($y + $stepH / 2 + 4) . "\" font-size=\"10\" fill=\"#374151\">" . number_format($val, 0) . "</text>\n";
        }

        $svg .= "</svg>\n";
        file_put_contents($path, $svg);

        return $path;
    }

    /**
     * Line chart: metric vs concurrency, one line per (cpu, io) combo.
     *
     * @param list<array<string, string>> $rows
     */
    private static function lineChart(array $rows, string $metric, string $title, string $yLabel, string $path): string
    {
        // Group by (cpu, io_ms) series
        $series = [];
        foreach ($rows as $r) {
            $cpu = $r['cpu'] ?? '0';
            $io = $r['io_ms'] ?? '0';
            $key = "cpu={$cpu} io={$io}ms";
            $c = (int) ($r['concurrency'] ?? 0);
            $val = (float) ($r[$metric] ?? 0);
            $series[$key][$c] = $val;
        }

        if ($series === []) {
            file_put_contents($path, self::emptySvg('No data'));

            return $path;
        }

        // Sort each series by concurrency
        foreach ($series as &$points) {
            ksort($points);
        }
        unset($points);

        // Compute bounds
        $allC = [];
        $allV = [];
        foreach ($series as $points) {
            foreach ($points as $c => $v) {
                $allC[$c] = true;
                $allV[] = $v;
            }
        }
        ksort($allC);
        $xValues = array_keys($allC);
        $minY = 0;
        $maxY = max($allV) * 1.1;
        if ($maxY <= 0) {
            $maxY = 1;
        }

        // SVG dimensions
        $marginLeft = 80;
        $marginTop = 50;
        $marginRight = 200;
        $marginBottom = 60;
        $plotW = 500;
        $plotH = 300;
        $w = $marginLeft + $plotW + $marginRight;
        $h = $marginTop + $plotH + $marginBottom;

        $svg = self::svgHeader($w, $h);
        $svg .= self::svgTitle($title, $w);

        // Grid lines
        $gridSteps = 5;
        for ($i = 0; $i <= $gridSteps; ++$i) {
            $ratio = $i / $gridSteps;
            $y = $marginTop + $plotH - $ratio * $plotH;
            $val = $minY + $ratio * ($maxY - $minY);
            $svg .= "<line x1=\"{$marginLeft}\" y1=\"{$y}\" x2=\"" . ($marginLeft + $plotW) . "\" y2=\"{$y}\" stroke=\"#e5e7eb\" stroke-width=\"1\"/>\n";
            $svg .= "<text x=\"" . ($marginLeft - 8) . "\" y=\"" . ($y + 4) . "\" text-anchor=\"end\" font-size=\"11\" fill=\"#6b7280\">" . self::formatNumber($val) . "</text>\n";
        }

        // X-axis ticks
        $xCount = count($xValues);
        foreach ($xValues as $xi => $c) {
            $x = $xCount > 1
                ? $marginLeft + ($xi / ($xCount - 1)) * $plotW
                : $marginLeft + $plotW / 2;
            $svg .= "<text x=\"{$x}\" y=\"" . ($marginTop + $plotH + 20) . "\" text-anchor=\"middle\" font-size=\"11\" fill=\"#6b7280\">{$c}</text>\n";
        }

        // Axis labels
        $svg .= "<text x=\"" . ($marginLeft + $plotW / 2) . "\" y=\"" . ($h - 10) . "\" text-anchor=\"middle\" font-size=\"13\" fill=\"#111827\" font-weight=\"600\">Concurrency</text>\n";
        $svg .= "<text x=\"15\" y=\"" . ($marginTop + $plotH / 2) . "\" text-anchor=\"middle\" font-size=\"13\" fill=\"#111827\" font-weight=\"600\" transform=\"rotate(-90, 15, " . ($marginTop + $plotH / 2) . ")\">{$yLabel}</text>\n";

        // Plot area border
        $svg .= "<rect x=\"{$marginLeft}\" y=\"{$marginTop}\" width=\"{$plotW}\" height=\"{$plotH}\" fill=\"none\" stroke=\"#d1d5db\" stroke-width=\"1\"/>\n";

        // Lines + dots
        $colorIdx = 0;
        $legendY = $marginTop + 5;

        foreach ($series as $label => $points) {
            $color = self::COLORS[$colorIdx % count(self::COLORS)];
            ++$colorIdx;

            $pathPoints = [];
            foreach ($points as $c => $v) {
                $xi = array_search($c, $xValues, true);
                $x = $xCount > 1
                    ? $marginLeft + ($xi / ($xCount - 1)) * $plotW
                    : $marginLeft + $plotW / 2;
                $y = $marginTop + $plotH - (($v - $minY) / ($maxY - $minY)) * $plotH;
                $pathPoints[] = [$x, $y];
            }

            // Line
            if (count($pathPoints) > 1) {
                $d = 'M ' . $pathPoints[0][0] . ' ' . $pathPoints[0][1];
                for ($i = 1, $cnt = count($pathPoints); $i < $cnt; ++$i) {
                    $d .= ' L ' . $pathPoints[$i][0] . ' ' . $pathPoints[$i][1];
                }
                $svg .= "<path d=\"{$d}\" fill=\"none\" stroke=\"{$color}\" stroke-width=\"2.5\" stroke-linejoin=\"round\"/>\n";
            }

            // Dots
            foreach ($pathPoints as [$px, $py]) {
                $svg .= "<circle cx=\"{$px}\" cy=\"{$py}\" r=\"4\" fill=\"{$color}\" stroke=\"#fff\" stroke-width=\"1.5\"/>\n";
            }

            // Legend
            $lx = $marginLeft + $plotW + 15;
            $svg .= "<line x1=\"{$lx}\" y1=\"{$legendY}\" x2=\"" . ($lx + 20) . "\" y2=\"{$legendY}\" stroke=\"{$color}\" stroke-width=\"2.5\"/>\n";
            $svg .= "<text x=\"" . ($lx + 25) . "\" y=\"" . ($legendY + 4) . "\" font-size=\"10\" fill=\"#374151\">{$label}</text>\n";
            $legendY += 18;
        }

        $svg .= "</svg>\n";
        file_put_contents($path, $svg);

        return $path;
    }

    // ── SVG helpers ──────────────────────────────────────────────────────

    private static function svgHeader(int $w, int $h): string
    {
        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$w} {$h}" width="{$w}" height="{$h}" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #fff;">
        <rect width="{$w}" height="{$h}" fill="#fff" rx="8"/>

        SVG;
    }

    private static function svgTitle(string $title, int $svgWidth): string
    {
        $x = $svgWidth / 2;
        $escaped = htmlspecialchars($title, ENT_XML1);

        return "<text x=\"{$x}\" y=\"30\" text-anchor=\"middle\" font-size=\"15\" font-weight=\"700\" fill=\"#111827\">{$escaped}</text>\n";
    }

    private static function emptySvg(string $message): string
    {
        $escaped = htmlspecialchars($message, ENT_XML1);

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 200" width="400" height="200" style="font-family: sans-serif;">
        <rect width="400" height="200" fill="#f9fafb" rx="8"/>
        <text x="200" y="105" text-anchor="middle" font-size="14" fill="#6b7280">{$escaped}</text>
        </svg>

        SVG;
    }

    /**
     * Map a value to a heat color (blue → green → yellow → red).
     */
    private static function heatColor(float $val, float $min, float $max): string
    {
        if ($max <= $min) {
            return '#60a5fa';
        }

        $ratio = ($val - $min) / ($max - $min);
        $ratio = max(0, min(1, $ratio));

        // 4-stop gradient: blue(0) → cyan(0.33) → yellow(0.66) → red(1)
        $stops = [
            [0.0, [59, 130, 246]],   // blue
            [0.33, [6, 182, 212]],    // cyan
            [0.66, [234, 179, 8]],    // yellow
            [1.0, [220, 38, 38]],     // red
        ];

        $r = $g = $b = 0;
        for ($i = 0, $cnt = count($stops) - 1; $i < $cnt; ++$i) {
            [$s0, $c0] = $stops[$i];
            [$s1, $c1] = $stops[$i + 1];
            if ($ratio >= $s0 && $ratio <= $s1) {
                $t = ($ratio - $s0) / ($s1 - $s0);
                $r = (int) round($c0[0] + $t * ($c1[0] - $c0[0]));
                $g = (int) round($c0[1] + $t * ($c1[1] - $c0[1]));
                $b = (int) round($c0[2] + $t * ($c1[2] - $c0[2]));

                break;
            }
        }

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Return black or white depending on background luminance.
     */
    private static function contrastColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.5 ? '#111827' : '#ffffff';
    }

    private static function formatNumber(float $val): string
    {
        if ($val >= 1000) {
            return number_format($val, 0);
        }
        if ($val >= 1) {
            return number_format($val, 1);
        }

        return number_format($val, 3);
    }
}
