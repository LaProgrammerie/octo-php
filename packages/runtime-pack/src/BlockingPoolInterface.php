<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

use InvalidArgumentException;
use Octo\RuntimePack\Exception\BlockingPoolFullException;
use Octo\RuntimePack\Exception\BlockingPoolSendException;
use Octo\RuntimePack\Exception\BlockingPoolTimeoutException;
use RuntimeException;

/**
 * Interface for the BlockingPool — isolates blocking/CPU-bound operations
 * in separate processes to prevent event loop starvation.
 *
 * IoExecutor depends on this interface, not the concrete implementation.
 * The full BlockingPool implementation is in Task 17.
 *
 * @see IoExecutor
 */
interface BlockingPoolInterface
{
    /**
     * Execute a named job in the blocking pool.
     *
     * @param string $jobName Registered job name (resolved via JobRegistry)
     * @param array<string, mixed> $payload Data for the job
     * @param null|float $timeout Timeout in seconds (null = pool default)
     *
     * @return mixed Job result
     *
     * @throws InvalidArgumentException If jobName is not registered
     * @throws BlockingPoolFullException If the pool queue is full
     * @throws BlockingPoolTimeoutException If the job exceeds timeout
     * @throws BlockingPoolSendException If the pool send fails
     * @throws RuntimeException If job execution fails
     */
    public function run(string $jobName, array $payload = [], ?float $timeout = null): mixed;
}
