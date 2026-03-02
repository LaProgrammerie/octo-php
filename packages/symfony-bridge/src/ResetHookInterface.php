<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge;

/**
 * Interface for custom reset hooks executed after the main Symfony reset.
 *
 * Each hook runs in a try/catch: a failing hook does not block subsequent hooks.
 * Register hooks via ResetManager::addHook() or auto-tag via the bundle's
 * ResetHookCompilerPass (tag: async_platform.reset_hook).
 */
interface ResetHookInterface
{
    public function reset(): void;
}
