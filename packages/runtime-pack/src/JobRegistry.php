<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

/**
 * Registry of named jobs for the BlockingPool.
 *
 * Maps job names (string) to callables. Populated at boot
 * (in onWorkerStart of pool workers).
 *
 * The IPC protocol sends {job_id, job_name, payload} via UnixSocket.
 * The pool worker resolves the callable by name from this registry,
 * executes it with the payload, and returns the result with the job_id.
 *
 * No closure serialization — the callable is resolved pool-side.
 */
final class JobRegistry
{
    /** @var array<string, callable(array): mixed> */
    private array $jobs = [];

    /**
     * Register a job handler.
     *
     * @param string $name Unique job name (e.g. 'pdf.generate', 'legacy.doctrine_query')
     * @param callable(array): mixed $handler Callable receiving payload, returning result
     * @throws \LogicException If the name is already registered
     */
    public function register(string $name, callable $handler): void
    {
        if (isset($this->jobs[$name])) {
            throw new \LogicException("Job '{$name}' already registered");
        }
        $this->jobs[$name] = $handler;
    }

    /**
     * Resolve a job by name.
     *
     * @throws \InvalidArgumentException If the job doesn't exist
     */
    public function resolve(string $name): callable
    {
        if (!isset($this->jobs[$name])) {
            throw new \InvalidArgumentException("Unknown job: '{$name}'");
        }
        return $this->jobs[$name];
    }

    /** Does the job exist in the registry? */
    public function has(string $name): bool
    {
        return isset($this->jobs[$name]);
    }

    /** List of registered job names. */
    public function names(): array
    {
        return array_keys($this->jobs);
    }
}
