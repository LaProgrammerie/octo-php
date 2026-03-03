<?php

declare(strict_types=1);

namespace Octo\SymfonyBundle\DependencyInjection\Compiler;

use Octo\SymfonyBridge\ResetHookInterface;
use Octo\SymfonyBridge\ResetManager;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Compiler pass that auto-tags services implementing ResetHookInterface
 * and injects them into the ResetManager via addHook() method calls.
 *
 * Two-phase approach:
 * 1. Auto-tag: find all services implementing ResetHookInterface and add
 *    the 'octo.reset_hook' tag if not already present.
 * 2. Inject: collect all tagged services and register them as method calls
 *    on the ResetManager definition.
 */
final class ResetHookCompilerPass implements CompilerPassInterface
{
    public const TAG = 'octo.reset_hook';

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ResetManager::class)) {
            return;
        }

        // Phase 1: Auto-tag services implementing ResetHookInterface
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;

            if (!$this->implementsResetHook($class)) {
                continue;
            }

            if (!$definition->hasTag(self::TAG)) {
                $definition->addTag(self::TAG);
            }
        }

        // Phase 2: Inject tagged services into ResetManager
        $resetManager = $container->getDefinition(ResetManager::class);

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $resetManager->addMethodCall('addHook', [new Reference($id)]);
        }
    }

    private function implementsResetHook(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        return is_subclass_of($class, ResetHookInterface::class);
    }
}
