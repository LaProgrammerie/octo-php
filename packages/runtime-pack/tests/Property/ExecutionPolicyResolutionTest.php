<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Property;

use Octo\RuntimePack\ExecutionPolicy;
use Octo\RuntimePack\ExecutionStrategy;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Define SWOOLE_HOOK_CURL if not available (no OpenSwoole extension in test env)
if (!\defined('SWOOLE_HOOK_CURL')) {
    \define('SWOOLE_HOOK_CURL', 1 << 23);
}

/**
 * Feature: runtime-pack-openswoole, Property 20: ExecutionPolicy résolution déterministe
 *
 * **Validates: Contrat Async — Invariant (politique d'exécution centralisée)**
 *
 * Property: For any registered dependency with strategy S, resolve() returns S.
 * For any unknown dependency, resolve() returns MustOffload (safe default).
 * canRunDirect() returns true iff strategy is DirectCoroutineOk.
 * defaults($hookFlags) positions guzzle=DirectCoroutineOk if SWOOLE_HOOK_CURL active,
 * ProbeRequired otherwise.
 *
 * Uses the real ExecutionPolicy and ExecutionStrategy classes.
 */
final class ExecutionPolicyResolutionTest extends TestCase
{
    use TestTrait;

    private const ALL_STRATEGIES = [
        ExecutionStrategy::DirectCoroutineOk,
        ExecutionStrategy::MustOffload,
        ExecutionStrategy::ProbeRequired,
    ];

    /**
     * Property 20a: Registered dependencies resolve to their registered strategy.
     *
     * Generate random dependency names with random strategies, register them,
     * then verify resolve() returns the correct strategy.
     */
    #[Test]
    public function registeredDependenciesResolveCorrectly(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(1, 10),    // number of dependencies to register
            Generators::choose(0, 2),     // strategy index for first dep
            Generators::choose(0, 2),     // strategy index for second dep (if exists)
        )->then(function (int $count, int $stratIdx1, int $stratIdx2): void {
            $policy = new ExecutionPolicy();
            $registrations = [];

            for ($i = 0; $i < $count; $i++) {
                $depName = "dep_{$i}";
                $stratIdx = ($i === 0) ? $stratIdx1 : (($i === 1) ? $stratIdx2 : $i % 3);
                $strategy = self::ALL_STRATEGIES[$stratIdx];
                $policy->register($depName, $strategy);
                $registrations[$depName] = $strategy;
            }

            // Verify each registered dependency resolves correctly
            foreach ($registrations as $dep => $expectedStrategy) {
                $resolved = $policy->resolve($dep);
                self::assertSame(
                    $expectedStrategy,
                    $resolved,
                    "Dependency '{$dep}' should resolve to '{$expectedStrategy->value}', got '{$resolved->value}'",
                );
            }
        });
    }

    /**
     * Property 20b: Unknown dependencies resolve to MustOffload.
     *
     * Generate random dependency names, register some, then query unknown ones.
     */
    #[Test]
    public function unknownDependenciesResolveMustOffload(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(0, 5),     // number of registered deps
            Generators::choose(1, 10),    // number of unknown deps to query
        )->then(function (int $regCount, int $unknownCount): void {
            $policy = new ExecutionPolicy();
            for ($i = 0; $i < $regCount; $i++) {
                $policy->register("known_{$i}", self::ALL_STRATEGIES[$i % 3]);
            }

            // Query unknown dependencies
            for ($i = 0; $i < $unknownCount; $i++) {
                $unknownDep = "unknown_{$i}";
                $resolved = $policy->resolve($unknownDep);
                self::assertSame(
                    ExecutionStrategy::MustOffload,
                    $resolved,
                    "Unknown dependency '{$unknownDep}' should resolve to MustOffload",
                );
            }
        });
    }

    /**
     * Property 20c: canRunDirect() returns true iff strategy is DirectCoroutineOk.
     */
    #[Test]
    public function canRunDirectTrueOnlyForDirectCoroutineOk(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(0, 2),     // strategy index
        )->then(function (int $stratIdx): void {
            $strategy = self::ALL_STRATEGIES[$stratIdx];
            $policy = new ExecutionPolicy();
            $policy->register('test_dep', $strategy);

            $canDirect = $policy->canRunDirect('test_dep');

            if ($strategy === ExecutionStrategy::DirectCoroutineOk) {
                self::assertTrue($canDirect, "canRunDirect should be true for DirectCoroutineOk");
            } else {
                self::assertFalse($canDirect, "canRunDirect should be false for {$strategy->value}");
            }
        });
    }

    /**
     * Property 20d: defaults() with SWOOLE_HOOK_CURL → guzzle=DirectCoroutineOk.
     * defaults() without SWOOLE_HOOK_CURL → guzzle=ProbeRequired.
     * Known safe deps always DirectCoroutineOk, blocking deps always MustOffload.
     */
    #[Test]
    public function defaultsPositionsGuzzleBasedOnHookCurl(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::bool(),           // curlHookActive
        )->then(function (bool $curlHookActive): void {
            $hookFlags = $curlHookActive ? SWOOLE_HOOK_CURL : 0;
            $policy = ExecutionPolicy::defaults($hookFlags);

            // Known coroutine-safe → DirectCoroutineOk
            self::assertSame(ExecutionStrategy::DirectCoroutineOk, $policy->resolve('redis'));
            self::assertSame(ExecutionStrategy::DirectCoroutineOk, $policy->resolve('file_io'));
            self::assertSame(ExecutionStrategy::DirectCoroutineOk, $policy->resolve('openswoole_http'));

            // Guzzle depends on SWOOLE_HOOK_CURL
            $expectedGuzzle = $curlHookActive
                ? ExecutionStrategy::DirectCoroutineOk
                : ExecutionStrategy::ProbeRequired;
            self::assertSame($expectedGuzzle, $policy->resolve('guzzle'));

            // Conditional deps → ProbeRequired
            self::assertSame(ExecutionStrategy::ProbeRequired, $policy->resolve('pdo_mysql'));
            self::assertSame(ExecutionStrategy::ProbeRequired, $policy->resolve('pdo_pgsql'));
            self::assertSame(ExecutionStrategy::ProbeRequired, $policy->resolve('doctrine_dbal'));

            // Blocking deps → MustOffload
            self::assertSame(ExecutionStrategy::MustOffload, $policy->resolve('ffi'));
            self::assertSame(ExecutionStrategy::MustOffload, $policy->resolve('cpu_bound'));

            // Unknown dep → MustOffload (safe default)
            self::assertSame(ExecutionStrategy::MustOffload, $policy->resolve('some_random_lib'));
        });
    }
}
