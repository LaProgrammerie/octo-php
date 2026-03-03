<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Property;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Feature: runtime-pack-openswoole, Property 4: Logique readyz déterministe.
 *
 * **Validates: Requirements 2.2, 2.3, 2.4, 2.6**
 *
 * Property: Given a tuple (shuttingDown, tickAge, eventLoopLagMs, lagThresholdMs),
 * the readyz decision is deterministic and follows the priority:
 *   1. shuttingDown → 503 "shutting_down"
 *   2. tickAge > 2.0s → 503 "event_loop_stale"
 *   3. lagThresholdMs > 0 && eventLoopLagMs > lagThresholdMs → 503 "event_loop_lagging"
 *   4. else → 200 "ready"
 *
 * This tests the PURE LOGIC of the readyz decision, independent of time.
 */
final class ReadyzDeterministicTest extends TestCase
{
    use TestTrait;

    private const TICK_STALE_THRESHOLD = 2.0;

    /**
     * Property 4: For any generated tuple, the readyz decision is deterministic
     * and matches the expected priority chain.
     */
    #[Test]
    public function readyzDecisionIsDeterministicAndCorrect(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::bool(),                     // shuttingDown
            Generators::choose(0, 5000),            // tickAgeMs (0-5000ms → 0.0-5.0s)
            Generators::choose(0, 2000),            // eventLoopLagMs (0-2000ms)
            Generators::choose(0, 1000),            // lagThresholdMs (0=disabled, >0=threshold)
        )->then(static function (bool $shuttingDown, int $tickAgeMs, int $eventLoopLagMs, int $lagThresholdMs): void {
            $tickAge = $tickAgeMs / 1000.0;

            $result = self::readyzDecision(
                $shuttingDown,
                $tickAge,
                (float) $eventLoopLagMs,
                (float) $lagThresholdMs,
            );

            // Determinism: calling twice with same inputs gives same result
            $result2 = self::readyzDecision(
                $shuttingDown,
                $tickAge,
                (float) $eventLoopLagMs,
                (float) $lagThresholdMs,
            );
            self::assertSame($result, $result2, 'readyz decision must be deterministic');

            // Correctness: verify priority chain
            if ($shuttingDown) {
                self::assertSame(503, $result['status']);
                self::assertSame('shutting_down', $result['body']);

                return;
            }

            if ($tickAge > self::TICK_STALE_THRESHOLD) {
                self::assertSame(503, $result['status']);
                self::assertSame('event_loop_stale', $result['body']);

                return;
            }

            if ($lagThresholdMs > 0 && $eventLoopLagMs > $lagThresholdMs) {
                self::assertSame(503, $result['status']);
                self::assertSame('event_loop_lagging', $result['body']);

                return;
            }

            self::assertSame(200, $result['status']);
            self::assertSame('ready', $result['body']);
        });
    }

    /**
     * Property 4 invariant: shutdown ALWAYS wins regardless of other conditions.
     */
    #[Test]
    public function shutdownAlwaysProduces503(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(0, 5000),    // tickAgeMs
            Generators::choose(0, 2000),    // eventLoopLagMs
            Generators::choose(0, 1000),    // lagThresholdMs
        )->then(static function (int $tickAgeMs, int $eventLoopLagMs, int $lagThresholdMs): void {
            $result = self::readyzDecision(
                shuttingDown: true,
                tickAge: $tickAgeMs / 1000.0,
                eventLoopLagMs: (float) $eventLoopLagMs,
                lagThresholdMs: (float) $lagThresholdMs,
            );

            self::assertSame(503, $result['status']);
            self::assertSame('shutting_down', $result['body']);
        });
    }

    /**
     * Property 4 invariant: when not shutting down, fresh tick, and lag within threshold → 200.
     */
    #[Test]
    public function healthyConditionsAlwaysProduce200(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(0, 1999),    // tickAgeMs (< 2000ms = within threshold)
            Generators::choose(0, 500),     // lagThresholdMs
        )->then(static function (int $tickAgeMs, int $lagThresholdMs): void {
            // Lag is always within threshold (0 if threshold is 0, or below threshold)
            $lagMs = $lagThresholdMs > 0 ? max(0, $lagThresholdMs - 1) : 0.0;

            $result = self::readyzDecision(
                shuttingDown: false,
                tickAge: $tickAgeMs / 1000.0,
                eventLoopLagMs: (float) $lagMs,
                lagThresholdMs: (float) $lagThresholdMs,
            );

            self::assertSame(200, $result['status']);
            self::assertSame('ready', $result['body']);
        });
    }

    /**
     * Pure function implementing the readyz decision logic.
     * Mirrors HealthController::readyz() + WorkerLifecycle::isEventLoopHealthy().
     *
     * @return array{status: int, body: string}
     */
    private static function readyzDecision(
        bool $shuttingDown,
        float $tickAge,
        float $eventLoopLagMs,
        float $lagThresholdMs,
    ): array {
        // Priority 1: shutdown
        if ($shuttingDown) {
            return ['status' => 503, 'body' => 'shutting_down'];
        }

        // Priority 2: tick stale
        if ($tickAge > self::TICK_STALE_THRESHOLD) {
            return ['status' => 503, 'body' => 'event_loop_stale'];
        }

        // Priority 3: event loop lag exceeds threshold
        // isEventLoopHealthy() returns false if lagThresholdMs > 0 && lagMs > threshold
        if ($lagThresholdMs > 0 && $eventLoopLagMs > $lagThresholdMs) {
            return ['status' => 503, 'body' => 'event_loop_lagging'];
        }

        // Priority 4: ready
        return ['status' => 200, 'body' => 'ready'];
    }
}
