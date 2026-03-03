<?php

declare(strict_types=1);

namespace Octo\SymfonyBundle\Tests\Unit;

use Octo\SymfonyBundle\DependencyInjection\Compiler\ResetHookCompilerPass;
use Octo\SymfonyBundle\OctoBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class OctoBundleTest extends TestCase
{
    public function testBuildRegistersResetHookCompilerPass(): void
    {
        $container = new ContainerBuilder();
        $bundle = new OctoBundle();

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
        $bundle = new OctoBundle();

        self::assertInstanceOf(Bundle::class, $bundle);
    }
}
