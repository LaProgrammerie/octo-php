<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Integration;

require_once __DIR__ . '/IntegrationTestDoubles.php';

use Octo\SymfonyBridge\HttpKernelAdapter;
use Octo\SymfonyBridge\MetricsBridge;
use Octo\SymfonyBridge\RequestIdProcessor;
use Octo\SymfonyBridge\ResetHookInterface;
use Octo\SymfonyBridge\ResetManager;
use Octo\SymfonyBundle\DependencyInjection\Compiler\ResetHookCompilerPass;
use Octo\SymfonyBundle\DependencyInjection\OctoExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Integration test: bundle auto-detection and service registration.
 *
 * Verifies:
 * - Auto-detection of optional packages via class_exists()
 * - YAML configuration loaded correctly
 * - Core services registered (HttpKernelAdapter, ResetManager, etc.)
 * - ResetHookCompilerPass auto-tags and injects hooks
 *
 * Requirements: 16.12, 16.13
 */
final class BundleIntegrationTest extends TestCase
{
    public function testCoreServicesRegistered(): void
    {
        $container = $this->loadExtension();

        self::assertTrue($container->hasDefinition(HttpKernelAdapter::class));
        self::assertTrue($container->hasDefinition(ResetManager::class));
        self::assertTrue($container->hasDefinition(RequestIdProcessor::class));
        self::assertTrue($container->hasDefinition(MetricsBridge::class));
    }

    public function testDefaultConfigurationValues(): void
    {
        $container = $this->loadExtension();

        self::assertSame(104_857_600, $container->getParameter('octo.memory_warning_threshold'));
        self::assertSame(50, $container->getParameter('octo.reset_warning_ms'));
        self::assertSame(0, $container->getParameter('octo.kernel_reboot_every'));
        self::assertSame(100, $container->getParameter('octo.messenger.channel_capacity'));
        self::assertSame(1, $container->getParameter('octo.messenger.consumers'));
        self::assertSame(5.0, $container->getParameter('octo.messenger.send_timeout'));
        self::assertSame(3600, $container->getParameter('octo.realtime.ws_max_lifetime_seconds'));
        self::assertTrue($container->getParameter('octo.otel.enabled'));
    }

    public function testCustomConfigurationOverrides(): void
    {
        $container = $this->loadExtension([
            'memory_warning_threshold' => 200_000_000,
            'reset_warning_ms' => 100,
            'kernel_reboot_every' => 500,
            'messenger' => [
                'channel_capacity' => 50,
                'consumers' => 4,
                'send_timeout' => 10.0,
            ],
            'realtime' => [
                'ws_max_lifetime_seconds' => 7200,
            ],
            'otel' => [
                'enabled' => false,
            ],
        ]);

        self::assertSame(200_000_000, $container->getParameter('octo.memory_warning_threshold'));
        self::assertSame(100, $container->getParameter('octo.reset_warning_ms'));
        self::assertSame(500, $container->getParameter('octo.kernel_reboot_every'));
        self::assertSame(50, $container->getParameter('octo.messenger.channel_capacity'));
        self::assertSame(4, $container->getParameter('octo.messenger.consumers'));
        self::assertSame(10.0, $container->getParameter('octo.messenger.send_timeout'));
        self::assertSame(7200, $container->getParameter('octo.realtime.ws_max_lifetime_seconds'));
        self::assertFalse($container->getParameter('octo.otel.enabled'));
    }

    public function testAutoDetectionMessengerPackageInstalled(): void
    {
        $container = $this->loadExtension();

        // Since symfony-messenger IS installed in dev, the transport should be registered
        self::assertTrue(
            $container->hasDefinition('octo.messenger.transport'),
            'Messenger transport should be auto-registered when package is installed',
        );
    }

    public function testAutoDetectionRealtimePackageInstalled(): void
    {
        $container = $this->loadExtension();

        // Since symfony-realtime IS installed in dev, the adapter should be registered
        self::assertTrue(
            $container->hasDefinition('octo.realtime.adapter'),
            'Realtime adapter should be auto-registered when package is installed',
        );
    }

    public function testAutoDetectionOtelPackageInstalled(): void
    {
        $container = $this->loadExtension();

        // Since symfony-otel IS installed in dev, OTEL services should be registered
        self::assertTrue(
            $container->hasDefinition('octo.otel.span_factory'),
            'OTEL span factory should be auto-registered when package is installed',
        );
    }

    public function testOtelDisabledDoesNotRegisterServices(): void
    {
        $container = $this->loadExtension([
            'otel' => ['enabled' => false],
        ]);

        self::assertFalse(
            $container->hasDefinition('octo.otel.span_factory'),
            'OTEL services should NOT be registered when otel.enabled=false',
        );
    }

    public function testResetHookCompilerPassAutoTagsAndInjects(): void
    {
        $container = new ContainerBuilder();
        $container->register('kernel', stdClass::class);
        $container->register('logger', NullLogger::class);

        $extension = new OctoExtension();
        $extension->load([[]], $container);

        // Register a service implementing ResetHookInterface
        $hookDef = new Definition(CountingResetHook::class);
        $container->setDefinition('app.my_reset_hook', $hookDef);

        // Run the compiler pass
        $pass = new ResetHookCompilerPass();
        $pass->process($container);

        // The hook should be tagged
        self::assertTrue($hookDef->hasTag(ResetHookCompilerPass::TAG));

        // The ResetManager should have addHook() method calls
        $resetManagerDef = $container->getDefinition(ResetManager::class);
        $methodCalls = $resetManagerDef->getMethodCalls();
        $addHookCalls = array_filter($methodCalls, static fn (array $call) => $call[0] === 'addHook');
        self::assertNotEmpty($addHookCalls, 'ResetManager should have addHook() calls for tagged services');
    }

    private function loadExtension(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // Provide required service stubs
        $container->register('kernel', stdClass::class);
        $container->register('logger', NullLogger::class);

        $extension = new OctoExtension();
        $extension->load([$config], $container);

        return $container;
    }
}
