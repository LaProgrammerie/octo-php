<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBundle;

use AsyncPlatform\SymfonyBundle\DependencyInjection\Compiler\ResetHookCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony Bundle for the Async PHP Platform.
 *
 * Responsibilities:
 * - Register core bridge services (HttpKernelAdapter, ResetManager, etc.)
 * - Auto-tag services implementing ResetHookInterface
 * - Auto-detect optional packages (messenger, realtime, otel)
 * - Provide async_platform configuration section
 */
final class AsyncPlatformBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ResetHookCompilerPass());
    }
}
