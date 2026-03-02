<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBundle\Tests\Unit;

use AsyncPlatform\SymfonyBundle\AsyncPlatformBundle;
use AsyncPlatform\SymfonyBundle\DependencyInjection\Compiler\ResetHookCompilerPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AsyncPlatformBundleTest extends TestCase
{
    public function testBuildRegistersResetHookCompilerPass(): void
    {
        $container = new ContainerBuilder();
        $bundle = new AsyncPlatformBundle();

        $bundle->build($container);

        $passes = $container->getCompilerPassConfig()->getBeforeOptimizationPasses();

        $found = false;
        foreach ($passes as $pass) {
            if ($pass instanceof ResetHookCompilerPass) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'ResetHookCompilerPass should be registered in the container');
    }

    public function testBundleExtendsSymfonyBundle(): void
    {
        $bundle = new AsyncPlatformBundle();

        self::assertInstanceOf(\Symfony\Component\HttpKernel\Bundle\Bundle::class, $bundle);
    }
}
