<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyMessenger;

/**
 * Abstraction over OpenSwoole\Coroutine\Channel for testability.
 *
 * In production, the real OpenSwoole channel is used.
 * In tests, a FakeChannel backed by SplQueue is injected.
 */
interface ChannelInterface
{
    /**
     * Push a value into the channel.
     *
     * @param float $timeout Timeout in seconds. -1 means no timeout.
     * @return bool True if pushed successfully, false on timeout/full.
     */
    public function push(mixed $value, float $timeout = -1): bool;

    /**
     * Pop a value from the channel.
     *
     * @param float $timeout Timeout in seconds. -1 means no timeout.
     * @return mixed The value, or false on timeout/empty.
     */
    public function pop(float $timeout = -1): mixed;

    /**
     * Returns the current number of elements in the channel.
     */
    public function length(): int;

    /**
     * Returns the channel capacity.
     */
    public function capacity(): int;

    /**
     * Returns true if the channel is full.
     */
    public function isFull(): bool;
}
