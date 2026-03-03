<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Property;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Feature: runtime-pack-openswoole, Property 19: Sémaphore de concurrence
 *
 * **Validates: Requirements 9.3 (concurrence bornée par worker)**
 *
 * Property: Given maxConcurrentScopes=N, at most N scopes can be active
 * simultaneously. Requests exceeding the limit receive 503.
 *
 * This is a PURE LOGIC test simulating the semaphore behavior using a simple
 * counter. ScopeRunner does NOT exist yet (Task 18). This test will be upgraded
 * to use the real class once implemented.
 *
 * The semaphore model: a counter initialized to N tokens.
 * - acquire(): if tokens > 0, decrement and return true (200). Else return false (503).
 * - release(): increment tokens (must not exceed N).
 */
final class SemaphoreConcurrencyTest extends TestCase
{
    use TestTrait;

    /**
     * Pure logic semaphore simulation.
     * Models the Channel-based semaphore pattern: pre-filled with N tokens,
     * pop() = acquire (non-blocking), push() = release.
     */
    private static function simulateSemaphore(int $maxConcurrent, array $operations): array
    {
        $tokens = $maxConcurrent; // Available tokens
        $active = 0;              // Currently active scopes
        $peakActive = 0;          // Max observed active scopes
        $rejected = 0;            // Count of 503 rejections
        $results = [];            // Per-operation result: 200 or 503

        foreach ($operations as $op) {
            if ($op === 'acquire') {
                if ($tokens > 0) {
                    $tokens--;
                    $active++;
                    $peakActive = max($peakActive, $active);
                    $results[] = 200;
                } else {
                    $rejected++;
                    $results[] = 503;
                }
            } elseif ($op === 'release') {
                if ($active > 0) {
                    $active--;
                    $tokens++;
                    $results[] = 'released';
                } else {
                    // No active scope to release — skip (shouldn't happen in valid sequences)
                    $results[] = 'noop';
                }
            }
        }

        return [
            'peakActive' => $peakActive,
            'rejected' => $rejected,
            'finalActive' => $active,
            'results' => $results,
        ];
    }

    /**
     * Generates a valid sequence of acquire/release operations.
     * Ensures releases only happen when there are active scopes.
     *
     * @return array{ops: string[], totalAcquires: int, totalReleases: int}
     */
    private static function generateOperationSequence(int $length, int $maxConcurrent): array
    {
        $ops = [];
        $active = 0;
        $tokens = $maxConcurrent;

        for ($i = 0; $i < $length; $i++) {
            // Decide: acquire or release
            if ($active === 0) {
                // Must acquire (nothing to release)
                $ops[] = 'acquire';
                if ($tokens > 0) {
                    $tokens--;
                    $active++;
                }
                // If tokens == 0, acquire will be rejected (503) but still valid
            } elseif (random_int(0, 1) === 0) {
                $ops[] = 'acquire';
                if ($tokens > 0) {
                    $tokens--;
                    $active++;
                }
            } else {
                $ops[] = 'release';
                $active--;
                $tokens++;
            }
        }

        return $ops;
    }

    /**
     * Property 19: At most N scopes are active simultaneously.
     */
    #[Test]
    public function atMostNScopesActiveSimultaneously(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(1, 20),    // maxConcurrentScopes (N)
            Generators::choose(5, 50),    // sequence length
        )->then(function (int $maxConcurrent, int $seqLength): void {
            $ops = self::generateOperationSequence($seqLength, $maxConcurrent);
            $result = self::simulateSemaphore($maxConcurrent, $ops);

            // INVARIANT: peak active scopes never exceeds N
            self::assertLessThanOrEqual(
                $maxConcurrent,
                $result['peakActive'],
                "Peak active ({$result['peakActive']}) must not exceed maxConcurrentScopes ({$maxConcurrent})",
            );
        });
    }

    /**
     * Property 19: Requests beyond N get 503.
     *
     * If we send N+M acquire requests without any releases,
     * exactly N succeed (200) and M are rejected (503).
     */
    #[Test]
    public function excessRequestsGet503(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(1, 10),    // maxConcurrentScopes (N)
            Generators::choose(1, 20),    // extra requests beyond N (M)
        )->then(function (int $maxConcurrent, int $extra): void {
            // Send N + extra acquire requests with no releases
            $totalRequests = $maxConcurrent + $extra;
            $ops = array_fill(0, $totalRequests, 'acquire');

            $result = self::simulateSemaphore($maxConcurrent, $ops);

            // Exactly N succeed, exactly $extra are rejected
            $accepted = array_count_values($result['results'])[200] ?? 0;
            $rejected = array_count_values($result['results'])[503] ?? 0;

            self::assertSame($maxConcurrent, $accepted, "Exactly N={$maxConcurrent} requests should be accepted");
            self::assertSame($extra, $rejected, "Exactly M={$extra} requests should be rejected with 503");
            self::assertSame($maxConcurrent, $result['peakActive'], "Peak active should equal N");
        });
    }

    /**
     * Property 19: Release restores capacity — after release, next acquire succeeds.
     */
    #[Test]
    public function releaseRestoresCapacity(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(1, 10),    // maxConcurrentScopes (N)
        )->then(function (int $maxConcurrent): void {
            // Fill all slots
            $ops = array_fill(0, $maxConcurrent, 'acquire');
            // Release one
            $ops[] = 'release';
            // Acquire one more — should succeed
            $ops[] = 'acquire';

            $result = self::simulateSemaphore($maxConcurrent, $ops);

            // The last acquire should have succeeded (200)
            $lastResult = end($result['results']);
            self::assertSame(200, $lastResult, 'Acquire after release should succeed');

            // Peak should still be N (never exceeded)
            self::assertSame($maxConcurrent, $result['peakActive']);
        });
    }
}
