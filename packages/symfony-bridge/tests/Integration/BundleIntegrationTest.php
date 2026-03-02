<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge\Tests\Integration;

require_once __DIR__ . '/IntegrationTestDoubles.php';

use AsyncPlatform\SymfonyBundle\DependencyInjection\AsyncPlatformExtension;
use AsyncPlatform\SymfonyBundle\DependencyInjection\Compiler\ResetHookCompilerPass;
use AsyncPlatform\SymfonyBridge\HttpKernelAdapter;
use AsyncPlatform\SymfonyBridge\ResetHookInterface;
use AsyncPlatform\SymfonyBridge\ResetManager;
use AsyncPlatform\SymfonyBridge\RequestIdProcessor;
use AsyncPlatform\SymfonyBridge\MetricsBridge;
use PHPUnit\Framework\TestCase;
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
    private function loadExtension(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // Provide required service stubs
        $container->register('kernel', \stdClass::class);
        $container->register('logger', \Psr\Log\NullLogger::class);

        $extension = new AsyncPlatformExtension();
        $extension->load([$config], $container);

        return $container;
    }

    public function testCoreServicesRegistered(): void
    {
        $container = $this->loadExtension();

        $this->assertTrue($container->hasDefinition(HttpKernelAdapter::class));
        $this->assertTrue($container->hasDefinition(ResetManager::class));
        $this->assertTrue($container->hasDefinition(RequestIdProcessor::class));
        $this->assertTrue($container->hasDefinition(MetricsBridge::class));
    }

    public function testDefaultConfigurationValues(): void
    {
        $container = $this->loadExtension();

        $this->assertSame(104_857_600, $container->getParameter('async_platform.memory_warning_threshold'));
        $this->assertSame(50, $container->getParameter('async_platform.reset_warning_ms'));
        $this->assertSame(0, $container->getParameter('async_platform.kernel_reboot_every'));
        $this->assertSame(100, $container->getParameter('async_platform.messenger.channel_capacity'));
        $this->assertSame(1, $container->getParameter('async_platform.messenger.consumers'));
        $this->assertSame(5.0, $container->getParameter('async_platform.messenger.send_timeout'));
        $this->assertSame(3600, $container->getParameter('async_platform.realtime.ws_max_lifetime_seconds'));
        $this->assertTrue($container->getParameter('async_platform.otel.enabled'));
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

        $this->assertSame(200_000_000, $container->getParameter('async_platform.memory_warning_threshold'));
        $this->assertSame(100, $container->getParameter('async_platform.reset_warning_ms'));
        $this->assertSame(500, $container->getParameter('async_platform.kernel_reboot_every'));
        $this->assertSame(50, $container->getParameter('async_platform.messenger.channel_capacity'));
        $this->assertSame(4, $container->getParameter('async_platform.messenger.consumers'));
        $this->assertSame(10.0, $container->getParameter('async_platform.messenger.send_timeout'));
        $this->assertSame(7200, $container->getParameter('async_platform.realtime.ws_max_lifetime_seconds'));
        $this->assertFalse($container->getParameter('async_platform.otel.enabled'));
    }

    public function testAutoDetectionMessengerPackageInstalled(): void
    {
        $container = $this->loadExtension();

        // Since symfony-messenger IS installed in dev, the transport should be registered
        $this->assertTrue(
            $container->hasDefinition('async_platform.messenger.transport'),
            'Messenger transport should be auto-registered when package is installed',
        );
    }

    public function testAutoDetectionRealtimePackageInstalled(): void
    {
        $container = $this->loadExtension();

        // Since symfony-realtime IS installed in dev, the adapter should be registered
        $this->assertTrue(
            $container->hasDefinition('async_platform.realtime.adapter'),
            'Realtime adapter should be auto-registered when package is installed',
        );
    }

    public function testAutoDetectionOtelPackageInstalled(): void
    {
        $container = $this->loadExtension();

        // Since symfony-otel IS installed in dev, OTEL services should be registered
        $this->assertTrue(
            $container->hasDefinition('async_platform.otel.span_factory'),
            'OTEL span factory should be auto-registered when package is installed',
        );
    }

    public function testOtelDisabledDoesNotRegisterServices(): void
    {
        $container = $this->loadExtension([
            'otel' => ['enabled' => false],
        ]);

        $this->assertFalse(
            $container->hasDefinition('async_platform.otel.span_factory'),
            'OTEL services should NOT be registered when otel.enabled=false',
        );
    }

    public function testResetHookCompilerPassAutoTagsAndInjects(): void
    {
        $container = new ContainerBuilder();
        $container->register('kernel', \stdClass::class);
        $container->register('logger', \Psr\Log\NullLogger::class);

        $extension = new AsyncPlatformExtension();
        $extension->load([[]], $container);

        // Register a service implementing ResetHookInterface
        $hookDef = new Definition(CountingResetHook::class);
        $container->setDefinition('app.my_reset_hook', $hookDef);

        // Run the compiler pass
        $pass = new ResetHookCompilerPass();
        $pass->process($container);

        // The hook should be tagged
        $this->assertTrue($hookDef->hasTag(ResetHookCompilerPass::TAG));

        // The ResetManager should have addHook() method calls
        $resetManagerDef = $container->getDefinition(ResetManager::class);
        $methodCalls = $resetManagerDef->getMethodCalls();
        $addHookCalls = array_filter($methodCalls, fn(array $call) => $call[0] === 'addHook');
        $this->assertNotEmpty($addHookCalls, 'ResetManager should have addHook() calls for tagged services');
    }
}
