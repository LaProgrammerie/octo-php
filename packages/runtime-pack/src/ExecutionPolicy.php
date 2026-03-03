<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

/**
 * Centralized execution policy for I/O dependencies.
 *
 * Determines per-dependency whether a call can run directly in a coroutine
 * (hooked by OpenSwoole) or must be offloaded to the BlockingPool.
 *
 * Reduces human error: developers don't need to know which libs are coroutine-safe.
 * The policy is configured once at boot and queried by IoExecutor at runtime.
 *
 * Unknown dependencies default to MustOffload (safe fallback).
 *
 * @see ExecutionStrategy
 * @see IoExecutor
 */
final class ExecutionPolicy
{
    /** @var array<string, ExecutionStrategy> Map dependency_name => strategy */
    private array $policies = [];

    /** Default strategy for unregistered dependencies. */
    private ExecutionStrategy $defaultStrategy = ExecutionStrategy::MustOffload;

    /**
     * Register the execution strategy for a dependency.
     *
     * @param string $dependency Dependency name (e.g. 'pdo_mysql', 'redis', 'guzzle', 'ffi')
     * @param ExecutionStrategy $strategy The execution strategy to apply
     */
    public function register(string $dependency, ExecutionStrategy $strategy): void
    {
        $this->policies[$dependency] = $strategy;
    }

    /**
     * Resolve the strategy for a dependency.
     *
     * Returns the registered strategy, or MustOffload (safe default) if unknown.
     */
    public function resolve(string $dependency): ExecutionStrategy
    {
        return $this->policies[$dependency] ?? $this->defaultStrategy;
    }

    /**
     * Can the dependency be called directly in a coroutine?
     *
     * Returns true only for DirectCoroutineOk.
     * ProbeRequired returns false (fallback to BlockingPool until proven safe).
     */
    public function canRunDirect(string $dependency): bool
    {
        return $this->resolve($dependency) === ExecutionStrategy::DirectCoroutineOk;
    }

    /**
     * Returns the full map of registered dependencies and their strategies.
     *
     * @return array<string, ExecutionStrategy>
     */
    public function all(): array
    {
        return $this->policies;
    }

    /**
     * Creates the default V1 policy based on the async compatibility matrix.
     *
     * Called at boot with the active hook flags to determine guzzle/curl strategy.
     *
     * Matrix:
     * - openswoole_http, redis, file_io → DirectCoroutineOk (always safe)
     * - guzzle → DirectCoroutineOk if SWOOLE_HOOK_CURL active, ProbeRequired otherwise
     * - pdo_mysql, pdo_pgsql, doctrine_dbal → ProbeRequired (needs integration proof)
     * - ffi, cpu_bound → MustOffload (always blocks event loop)
     * - unknown → MustOffload (safe default)
     *
     * @param int $hookFlags Active hook flags (from Runtime::getHookFlags())
     */
    public static function defaults(int $hookFlags = 0): self
    {
        $policy = new self();

        // Always coroutine-safe (hooked by OpenSwoole)
        $policy->register('openswoole_http', ExecutionStrategy::DirectCoroutineOk);
        $policy->register('redis', ExecutionStrategy::DirectCoroutineOk);
        $policy->register('file_io', ExecutionStrategy::DirectCoroutineOk);

        // Guzzle: DirectCoroutineOk only if SWOOLE_HOOK_CURL is active.
        // Otherwise ProbeRequired — Guzzle's transport may use PHP stream wrappers
        // (hooked via SWOOLE_HOOK_FILE), but this isn't guaranteed without explicit config.
        $curlHookActive = ($hookFlags & SWOOLE_HOOK_CURL) === SWOOLE_HOOK_CURL;
        $policy->register(
            'guzzle',
            $curlHookActive
            ? ExecutionStrategy::DirectCoroutineOk
            : ExecutionStrategy::ProbeRequired
        );

        // Conditional — needs integration proof on prod image
        $policy->register('pdo_mysql', ExecutionStrategy::ProbeRequired);
        $policy->register('pdo_pgsql', ExecutionStrategy::ProbeRequired);
        $policy->register('doctrine_dbal', ExecutionStrategy::ProbeRequired);

        // Always blocking — must offload
        $policy->register('ffi', ExecutionStrategy::MustOffload);
        $policy->register('cpu_bound', ExecutionStrategy::MustOffload);

        return $policy;
    }
}
