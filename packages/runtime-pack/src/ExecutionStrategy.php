<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

/**
 * Execution strategy for an I/O dependency in the async runtime.
 *
 * Determines whether a dependency can be called directly in a coroutine
 * (hooked by OpenSwoole) or must be offloaded to the BlockingPool.
 *
 * - DirectCoroutineOk: Safe to call in coroutine (yields via hooks). Zero overhead.
 * - MustOffload: Must be offloaded to BlockingPool (blocking/CPU-bound).
 * - ProbeRequired: Needs integration proof before direct use. Offloaded until proven safe.
 *
 * Default for unknown dependencies: MustOffload (safe fallback).
 *
 * @see ExecutionPolicy
 * @see IoExecutor
 */
enum ExecutionStrategy: string
{
    case DirectCoroutineOk = 'DIRECT_COROUTINE_OK';
    case MustOffload = 'MUST_OFFLOAD';
    case ProbeRequired = 'PROBE_REQUIRED';
}
