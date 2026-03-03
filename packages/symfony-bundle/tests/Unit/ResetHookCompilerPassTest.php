<?php

declare(strict_types=1);

namespace Octo\SymfonyBundle\Tests\Unit;

use Octo\SymfonyBridge\ResetHookInterface;
use Octo\SymfonyBridge\ResetManager;
use Octo\SymfonyBundle\DependencyInjection\Compiler\ResetHookCompilerPass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class ResetHookCompilerPassTest extends TestCase
{
    public function testAutoTagsServicesImplementingResetHookInterface(): void
    {
        $container = new ContainerBuilder();

        // Register ResetManager
        $resetManagerDef = new Definition(ResetManager::class);
        $container->setDefinition(ResetManager::class, $resetManagerDef);

        // Register a service implementing ResetHookInterface (without tag)
        $hookDef = new Definition(TestResetHook::class);
        $container->setDefinition(TestResetHook::class, $hookDef);

        $pass = new ResetHookCompilerPass();
        $pass->process($container);

        // The hook should be auto-tagged
        self::assertTrue($hookDef->hasTag(ResetHookCompilerPass::TAG));

        // The ResetManager should have an addHook method call
        $methodCalls = $resetManagerDef->getMethodCalls();
        self::assertCount(1, $methodCalls);
        self::assertSame('addHook', $methodCalls[0][0]);
        self::assertInstanceOf(Reference::class, $methodCalls[0][1][0]);
        self::assertSame(TestResetHook::class, (string) $methodCalls[0][1][0]);
    }

    public function testDoesNotDuplicateExistingTag(): void
    {
        $container = new ContainerBuilder();

        $resetManagerDef = new Definition(ResetManager::class);
        $container->setDefinition(ResetManager::class, $resetManagerDef);

        // Register a hook that already has the tag
        $hookDef = new Definition(TestResetHook::class);
        $hookDef->addTag(ResetHookCompilerPass::TAG);
        $container->setDefinition(TestResetHook::class, $hookDef);

        $pass = new ResetHookCompilerPass();
        $pass->process($container);

        // Should still have exactly one tag (not duplicated)
        $tags = $hookDef->getTag(ResetHookCompilerPass::TAG);
        self::assertCount(1, $tags);

        // ResetManager should have one addHook call
        $methodCalls = $resetManagerDef->getMethodCalls();
        self::assertCount(1, $methodCalls);
    }

    public function testSkipsWhenResetManagerNotRegistered(): void
    {
        $container = new ContainerBuilder();

        $hookDef = new Definition(TestResetHook::class);
        $container->setDefinition(TestResetHook::class, $hookDef);

        $pass = new ResetHookCompilerPass();
        $pass->process($container);

        // No tag should be added (ResetManager not present)
        self::assertFalse($hookDef->hasTag(ResetHookCompilerPass::TAG));
    }

    public function testIgnoresServicesNotImplementingResetHookInterface(): void
    {
        $container = new ContainerBuilder();

        $resetManagerDef = new Definition(ResetManager::class);
        $container->setDefinition(ResetManager::class, $resetManagerDef);

        // Register a service that does NOT implement ResetHookInterface
        $nonHookDef = new Definition(stdClass::class);
        $container->setDefinition('some.service', $nonHookDef);

        $pass = new ResetHookCompilerPass();
        $pass->process($container);

        self::assertFalse($nonHookDef->hasTag(ResetHookCompilerPass::TAG));
        self::assertEmpty($resetManagerDef->getMethodCalls());
    }

    public function testMultipleHooksInjected(): void
    {
        $container = new ContainerBuilder();

        $resetManagerDef = new Definition(ResetManager::class);
        $container->setDefinition(ResetManager::class, $resetManagerDef);

        $hook1 = new Definition(TestResetHook::class);
        $container->setDefinition(TestResetHook::class, $hook1);

        $hook2 = new Definition(AnotherTestResetHook::class);
        $container->setDefinition(AnotherTestResetHook::class, $hook2);

        $pass = new ResetHookCompilerPass();
        $pass->process($container);

        $methodCalls = $resetManagerDef->getMethodCalls();
        self::assertCount(2, $methodCalls);
    }
}

// --- Test doubles ---

final class TestResetHook implements ResetHookInterface
{
    public function reset(): void {}
}

final class AnotherTestResetHook implements ResetHookInterface
{
    public function reset(): void {}
}
