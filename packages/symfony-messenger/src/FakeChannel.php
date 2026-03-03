<?php

declare(strict_types=1);

namespace Octo\SymfonyMessenger;

use InvalidArgumentException;
use Override;
use SplQueue;

/**
 * In-memory channel backed by SplQueue for testing without OpenSwoole.
 *
 * Simulates bounded channel semantics:
 * - push() returns false when capacity is reached (no blocking)
 * - pop() returns false when empty (no blocking)
 *
 * This is NOT a coroutine-aware implementation. It is designed for
 * unit and property-based tests only. The real OpenSwoole channel
 * provides coroutine-level blocking/yielding in production.
 */
final class FakeChannel implements ChannelInterface
{
    /** @var SplQueue<mixed> */
    private SplQueue $queue;

    public function __construct(
        private readonly int $cap,
    ) {
        if ($cap < 1) {
            throw new InvalidArgumentException('Channel capacity must be >= 1');
        }
        $this->queue = new SplQueue();
    }

    #[Override]
    public function push(mixed $value, float $timeout = -1): bool
    {
        if ($this->queue->count() >= $this->cap) {
            return false;
        }
        $this->queue->enqueue($value);

        return true;
    }

    #[Override]
    public function pop(float $timeout = -1): mixed
    {
        if ($this->queue->isEmpty()) {
            return false;
        }

        return $this->queue->dequeue();
    }

    #[Override]
    public function length(): int
    {
        return $this->queue->count();
    }

    #[Override]
    public function capacity(): int
    {
        return $this->cap;
    }

    #[Override]
    public function isFull(): bool
    {
        return $this->queue->count() >= $this->cap;
    }
}
