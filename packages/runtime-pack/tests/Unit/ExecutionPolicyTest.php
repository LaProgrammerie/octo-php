<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use const SWOOLE_HOOK_CURL;

use Octo\RuntimePack\ExecutionPolicy;
use Octo\RuntimePack\ExecutionStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function define;
use function defined;

// Define SWOOLE_HOOK_CURL if not available (no OpenSwoole extension in test env)
if (!defined('SWOOLE_HOOK_CURL')) {
    define('SWOOLE_HOOK_CURL', 1 << 23);
}

/**
 * Unit tests for ExecutionPolicy.
 *
 * Validates: Contrat Async — Invariant (politique d'exécution centralisée) | Property 20
 */
final class ExecutionPolicyTest extends TestCase
{
    #[Test]
    public function registerAndResolveRoundTrip(): void
    {
        $policy = new ExecutionPolicy();
        $policy->register('redis', ExecutionStrategy::DirectCoroutineOk);
        $policy->register('ffi', ExecutionStrategy::MustOffload);
        $policy->register('pdo_mysql', ExecutionStrategy::ProbeRequired);

        self::assertSame(ExecutionStrategy::DirectCoroutineOk, $policy->resolve('redis'));
        self::assertSame(ExecutionStrategy::MustOffload, $policy->resolve('ffi'));
        self::assertSame(ExecutionStrategy::ProbeRequired, $policy->resolve('pdo_mysql'));
    }

    #[Test]
    public function resolveUnknownDependencyReturnsMustOffload(): void
    {
        $policy = new ExecutionPolicy();
        $policy->register('redis', ExecutionStrategy::DirectCoroutineOk);

        self::assertSame(ExecutionStrategy::MustOffload, $policy->resolve('unknown_lib'));
        self::assertSame(ExecutionStrategy::MustOffload, $policy->resolve(''));
        self::assertSame(ExecutionStrategy::MustOffload, $policy->resolve('some_random_dep'));
    }

    #[Test]
    public function canRunDirectTrueOnlyForDirectCoroutineOk(): void
    {
        $policy = new ExecutionPolicy();
        $policy->register('redis', ExecutionStrategy::DirectCoroutineOk);
        $policy->register('ffi', ExecutionStrategy::MustOffload);
        $policy->register('pdo_mysql', ExecutionStrategy::ProbeRequired);

        self::assertTrue($policy->canRunDirect('redis'));
        self::assertFalse($policy->canRunDirect('ffi'));
        self::assertFalse($policy->canRunDirect('pdo_mysql'));
        self::assertFalse($policy->canRunDirect('unknown'));
    }

    #[Test]
    public function registerOverridesExistingStrategy(): void
    {
        $policy = new ExecutionPolicy();
        $policy->register('pdo_mysql', ExecutionStrategy::ProbeRequired);
        self::assertSame(ExecutionStrategy::ProbeRequired, $policy->resolve('pdo_mysql'));

        // Override after integration proof
        $policy->register('pdo_mysql', ExecutionStrategy::DirectCoroutineOk);
        self::assertSame(ExecutionStrategy::DirectCoroutineOk, $policy->resolve('pdo_mysql'));
        self::assertTrue($policy->canRunDirect('pdo_mysql'));
    }

    #[Test]
    public function allReturnsFullMap(): void
    {
        $policy = new ExecutionPolicy();
        self::assertSame([], $policy->all());

        $policy->register('redis', ExecutionStrategy::DirectCoroutineOk);
        $policy->register('ffi', ExecutionStrategy::MustOffload);

        $all = $policy->all();
        self::assertCount(2, $all);
        self::assertSame(ExecutionStrategy::DirectCoroutineOk, $all['redis']);
        self::assertSame(ExecutionStrategy::MustOffload, $all['ffi']);
    }

    #[Test]
    public function defaultsWithCurlHookActive(): void
    {
        $policy = ExecutionPolicy::defaults(SWOOLE_HOOK_CURL);

        // Always coroutine-safe
        self::assertSame(ExecutionStrategy::DirectCoroutineOk, $policy->resolve('openswoole_http'));
        self::assertSame(ExecutionStrategy::DirectCoroutineOk, $policy->resolve('redis'));
        self::assertSame(ExecutionStrategy::DirectCoroutineOk, $policy->resolve('file_io'));

        // Guzzle = DirectCoroutineOk when SWOOLE_HOOK_CURL active
        self::assertSame(ExecutionStrategy::DirectCoroutineOk, $policy->resolve('guzzle'));
        self::assertTrue($policy->canRunDirect('guzzle'));

        // Conditional — needs integration proof
        self::assertSame(ExecutionStrategy::ProbeRequired, $policy->resolve('pdo_mysql'));
        self::assertSame(ExecutionStrategy::ProbeRequired, $policy->resolve('pdo_pgsql'));
        self::assertSame(ExecutionStrategy::ProbeRequired, $policy->resolve('doctrine_dbal'));

        // Always blocking
        self::assertSame(ExecutionStrategy::MustOffload, $policy->resolve('ffi'));
        self::assertSame(ExecutionStrategy::MustOffload, $policy->resolve('cpu_bound'));

        // Unknown → MustOffload
        self::assertSame(ExecutionStrategy::MustOffload, $policy->resolve('unknown'));
    }

    #[Test]
    public function defaultsWithoutCurlHook(): void
    {
        $policy = ExecutionPolicy::defaults(0);

        // Guzzle = ProbeRequired when SWOOLE_HOOK_CURL inactive
        self::assertSame(ExecutionStrategy::ProbeRequired, $policy->resolve('guzzle'));
        self::assertFalse($policy->canRunDirect('guzzle'));

        // Other deps unchanged
        self::assertSame(ExecutionStrategy::DirectCoroutineOk, $policy->resolve('redis'));
        self::assertSame(ExecutionStrategy::DirectCoroutineOk, $policy->resolve('file_io'));
        self::assertSame(ExecutionStrategy::DirectCoroutineOk, $policy->resolve('openswoole_http'));
    }

    #[Test]
    public function defaultsContainsExpectedDependencyCount(): void
    {
        $policy = ExecutionPolicy::defaults(0);
        $all = $policy->all();

        // 9 dependencies: openswoole_http, redis, file_io, guzzle, pdo_mysql, pdo_pgsql, doctrine_dbal, ffi, cpu_bound
        self::assertCount(9, $all);
        self::assertArrayHasKey('openswoole_http', $all);
        self::assertArrayHasKey('redis', $all);
        self::assertArrayHasKey('file_io', $all);
        self::assertArrayHasKey('guzzle', $all);
        self::assertArrayHasKey('pdo_mysql', $all);
        self::assertArrayHasKey('pdo_pgsql', $all);
        self::assertArrayHasKey('doctrine_dbal', $all);
        self::assertArrayHasKey('ffi', $all);
        self::assertArrayHasKey('cpu_bound', $all);
    }
}
