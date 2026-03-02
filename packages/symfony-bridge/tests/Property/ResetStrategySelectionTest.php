<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge\Tests\Property;

require_once __DIR__ . '/../Unit/TestDoubles.php';

use AsyncPlatform\SymfonyBridge\ResetManager;
use AsyncPlatform\SymfonyBridge\Tests\Unit\BareKernel;
use AsyncPlatform\SymfonyBridge\Tests\Unit\FakeContainer;
use AsyncPlatform\SymfonyBridge\Tests\Unit\FakeServicesResetter;
use AsyncPlatform\SymfonyBridge\Tests\Unit\KernelWithContainer;
use AsyncPlatform\SymfonyBridge\Tests\Unit\ResettableKernel;
use AsyncPlatform\SymfonyBridge\Tests\Unit\SpyLogger;
use AsyncPlatform\SymfonyBridge\Tests\Unit\SpyResetHook;
use AsyncPlatform\SymfonyBridge\Tests\Unit\ThrowingResettableKernel;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Property 6: Reset always executes with correct strategy
 *
 * **Validates: Requirements 4.4, 4.5, 4.6**
 *
 * For any kernel (implementing or not ResetInterface, with or without services_resetter),
 * the ResetManager SHALL select the correct reset strategy according to the defined priority
 * (ResetInterface > services_resetter > best-effort), execute the reset in a finally block
 * (even if the handler threw an exception), and execute all registered ResetHookInterface
 * hooks after the main reset.
 */
final class ResetStrategySelectionTest extends TestCase
{
    use TestTrait;

    /**
     * Kernel capability variants for generation.
     * 0 = ResetInterface kernel
     * 1 = kernel with services_resetter in container
     * 2 = bare kernel (best-effort)
     * 3 = throwing ResetInterface kernel (tests finally semantics)
     */
    private const STRATEGY_RESET_INTERFACE = 0;
    private const STRATEGY_SERVICES_RESETTER = 1;
    private const STRATEGY_BEST_EFFORT = 2;
    private const STRATEGY_THROWING = 3;

    #[Test]
    public function correct_strategy_is_selected_for_any_kernel_capability(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(0, 3),
            Generators::choose(0, 5),
        )->then(function (int $strategyType, int $hookCount): void {
            $logger = new SpyLogger();
            $kernel = match ($strategyType) {
                self::STRATEGY_RESET_INTERFACE => new ResettableKernel(),
                self::STRATEGY_SERVICES_RESETTER => new KernelWithContainer(
                    new FakeContainer(['services_resetter' => new FakeServicesResetter()]),
                ),
                self::STRATEGY_BEST_EFFORT => new BareKernel(),
                self::STRATEGY_THROWING => new ThrowingResettableKernel(),
            };

            $hooks = [];
            for ($i = 0; $i < $hookCount; $i++) {
                $hooks[] = new SpyResetHook();
            }

            $manager = new ResetManager($kernel, $logger, null);
            foreach ($hooks as $hook) {
                $manager->addHook($hook);
            }

            $manager->reset('pbt-req-' . $strategyType);

            // Verify correct strategy was selected
            match ($strategyType) {
                self::STRATEGY_RESET_INTERFACE => self::assertTrue(
                    $kernel->resetCalled,
                    'ResetInterface kernel: reset() must be called',
                ),
                self::STRATEGY_SERVICES_RESETTER => self::assertTrue(
                    $kernel->container->servicesResetter->resetCalled,
                    'services_resetter: reset() must be called',
                ),
                self::STRATEGY_BEST_EFFORT => self::assertTrue(
                    $logger->hasWarningContaining('No complete reset strategy found'),
                    'Best-effort: warning must be logged',
                ),
                self::STRATEGY_THROWING => self::assertTrue(
                    $logger->hasErrorContaining('Reset failed'),
                    'Throwing kernel: error must be logged',
                ),
            };

            // All hooks must execute regardless of strategy or exception
            foreach ($hooks as $idx => $hook) {
                self::assertTrue(
                    $hook->called,
                    "Hook #{$idx} must be called (strategy={$strategyType})",
                );
            }

            // Debug log with duration must always be present
            $debugLogs = array_filter(
                $logger->logs,
                fn(array $log) => $log['level'] === 'debug'
                && str_contains($log['message'], 'Reset completed'),
            );
            self::assertNotEmpty(
                $debugLogs,
                'Debug log with reset duration must always be emitted',
            );
        });
    }
}
