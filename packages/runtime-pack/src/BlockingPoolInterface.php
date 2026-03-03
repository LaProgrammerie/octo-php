<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

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
     * @param array $payload Data for the job
     * @param float|null $timeout Timeout in seconds (null = pool default)
     * @return mixed Job result
     *
     * @throws \InvalidArgumentException If jobName is not registered
     */
    public function run(string $jobName, array $payload = [], ?float $timeout = null): mixed;
}
