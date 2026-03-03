<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

/**
 * I/O execution wrapper with automatic strategy routing.
 *
 * Chooses between direct coroutine execution and BlockingPool offload
 * based on the centralized ExecutionPolicy. Developers don't need to know
 * which libs are coroutine-safe — IoExecutor decides automatically.
 *
 * Routing logic:
 * - DirectCoroutineOk + directCallable provided → call directly (zero overhead)
 * - MustOffload → offload to BlockingPool
 * - ProbeRequired → offload to BlockingPool + log debug message
 * - No directCallable → always offload regardless of strategy
 *
 * Usage:
 *   $result = $io->run('redis', 'cache.get', ['key' => 'foo'], fn($p) => $redis->get($p['key']));
 *
 * @see ExecutionPolicy
 * @see ExecutionStrategy
 */
final class IoExecutor
{
    public function __construct(
        private readonly ExecutionPolicy $policy,
        private readonly BlockingPoolInterface $blockingPool,
        private readonly JsonLogger $logger,
    ) {
    }

    /**
     * Execute an I/O operation according to the dependency's policy.
     *
     * @param string $dependency Dependency name (e.g. 'pdo_mysql', 'redis', 'guzzle')
     * @param string $jobName BlockingPool job name (used if offloading)
     * @param array $payload Data for the job
     * @param callable|null $directCallable Callable for direct coroutine execution (null = always offload)
     * @param float|null $timeout Timeout in seconds (null = BlockingPool default)
     * @return mixed Operation result
     */
    public function run(
        string $dependency,
        string $jobName,
        array $payload = [],
        ?callable $directCallable = null,
        ?float $timeout = null,
    ): mixed {
        $strategy = $this->policy->resolve($dependency);

        if ($strategy === ExecutionStrategy::DirectCoroutineOk && $directCallable !== null) {
            // Direct execution in the request coroutine — hooked by OpenSwoole
            return $directCallable($payload);
        }

        // Offload to BlockingPool (MustOffload, ProbeRequired, or no directCallable)
        if ($strategy === ExecutionStrategy::ProbeRequired) {
            $this->logger->debug('IoExecutor: offloading to BlockingPool (probe required)', [
                'dependency' => $dependency,
                'job_name' => $jobName,
            ]);
        }

        return $this->blockingPool->run($jobName, $payload, $timeout);
    }
}
