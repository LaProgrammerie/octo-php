<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack;

/**
 * Cooperative yield helper for CPU-bound work in coroutines.
 *
 * Problem: OpenSwoole coroutines are cooperatively scheduled. A coroutine
 * doing CPU-heavy work (hashing, JSON processing, image manipulation)
 * monopolizes the event loop, starving other coroutines from resuming
 * after IO completion. This causes the "convoy effect" where IO wait
 * times explode under mixed CPU+IO workloads.
 *
 * Solution: Insert explicit yield points in CPU-heavy loops. This class
 * provides a lightweight counter-based yield mechanism that calls
 * Coroutine::usleep(0) every N iterations, giving the scheduler a
 * chance to resume other coroutines.
 *
 * Usage:
 *   $yield = new CooperativeYield(every: 1000);
 *   for ($i = 0; $i < 100_000; $i++) {
 *       $yield->tick();
 *       // ... CPU work ...
 *   }
 *
 * Or as a static one-liner in tight loops:
 *   CooperativeYield::maybeYield($i, 1000);
 *
 * Cost: ~0.1μs per tick() call (counter increment + comparison).
 * Yield cost: ~5-15μs per actual usleep(0) call.
 *
 * @see WorkerLifecycle for event loop lag detection
 */
final class CooperativeYield
{
    private int $counter = 0;

    /**
     * @param int $every Yield every N ticks. Must be > 0.
     */
    public function __construct(
        private readonly int $every = 1000,
    ) {
        if ($every <= 0) {
            throw new \InvalidArgumentException('CooperativeYield::every must be > 0');
        }
    }

    /**
     * Increment counter and yield if threshold reached.
     * Call this inside tight CPU loops.
     */
    public function tick(): void
    {
        if (++$this->counter >= $this->every) {
            $this->counter = 0;
            \OpenSwoole\Coroutine::usleep(0);
        }
    }

    /**
     * Reset the internal counter (e.g. between batches).
     */
    public function reset(): void
    {
        $this->counter = 0;
    }

    /**
     * Static helper for use in loops where creating an instance is impractical.
     *
     * @param int $iteration Current loop iteration (0-based)
     * @param int $every     Yield every N iterations
     */
    public static function maybeYield(int $iteration, int $every = 1000): void
    {
        if ($every > 0 && $iteration > 0 && $iteration % $every === 0) {
            \OpenSwoole\Coroutine::usleep(0);
        }
    }
}
