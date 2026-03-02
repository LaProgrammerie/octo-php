<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBundle\Tests\Unit;

use AsyncPlatform\SymfonyBridge\HttpKernelAdapter;
use AsyncPlatform\SymfonyBridge\MetricsBridge;
use AsyncPlatform\SymfonyBridge\RequestIdProcessor;
use AsyncPlatform\SymfonyBridge\ResetManager;
use AsyncPlatform\SymfonyBundle\DependencyInjection\AsyncPlatformExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests that the AsyncPlatformExtension correctly loads configuration,
 * registers core bridge services, and handles optional package auto-detection.
 */
final class AsyncPlatformExtensionTest extends TestCase
{
    private ContainerBuilder $container;
    private AsyncPlatformExtension $extension;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->extension = new AsyncPlatformExtension();
    }

    public function testCoreServicesAreRegistered(): void
    {
        $this->extension->load([], $this->container);

        self::assertTrue($this->container->hasDefinition(HttpKernelAdapter::class));
        self::assertTrue($this->container->hasDefinition(ResetManager::class));
        self::assertTrue($this->container->hasDefinition(RequestIdProcessor::class));
        self::assertTrue($this->container->hasDefinition(MetricsBridge::class));
    }

    public function testDefaultParametersAreSet(): void
    {
        $this->extension->load([], $this->container);

        self::assertSame(104_857_600, $this->container->getParameter('async_platform.memory_warning_threshold'));
        self::assertSame(50, $this->container->getParameter('async_platform.reset_warning_ms'));
        self::assertSame(0, $this->container->getParameter('async_platform.kernel_reboot_every'));
    }

    public function testCustomConfigMapsToParameters(): void
    {
        $this->extension->load([
            [
                'memory_warning_threshold' => 200_000_000,
                'reset_warning_ms' => 100,
                'kernel_reboot_every' => 1000,
            ],
        ], $this->container);

        self::assertSame(200_000_000, $this->container->getParameter('async_platform.memory_warning_threshold'));
        self::assertSame(100, $this->container->getParameter('async_platform.reset_warning_ms'));
        self::assertSame(1000, $this->container->getParameter('async_platform.kernel_reboot_every'));
    }

    public function testMessengerParametersAreSet(): void
    {
        $this->extension->load([], $this->container);

        self::assertSame(100, $this->container->getParameter('async_platform.messenger.channel_capacity'));
        self::assertSame(1, $this->container->getParameter('async_platform.messenger.consumers'));
        self::assertSame(5.0, $this->container->getParameter('async_platform.messenger.send_timeout'));
    }

    public function testRealtimeParametersAreSet(): void
    {
        $this->extension->load([], $this->container);

        self::assertSame(3600, $this->container->getParameter('async_platform.realtime.ws_max_lifetime_seconds'));
    }

    public function testOtelParametersAreSet(): void
    {
        $this->extension->load([], $this->container);

        self::assertTrue($this->container->getParameter('async_platform.otel.enabled'));
    }

    public function testRequestIdProcessorHasMonologTag(): void
    {
        $this->extension->load([], $this->container);

        $definition = $this->container->getDefinition(RequestIdProcessor::class);
        self::assertTrue($definition->hasTag('monolog.processor'));
    }

    public function testHttpKernelAdapterIsPublic(): void
    {
        $this->extension->load([], $this->container);

        $definition = $this->container->getDefinition(HttpKernelAdapter::class);
        self::assertTrue($definition->isPublic());
    }

    public function testResetManagerIsPublic(): void
    {
        $this->extension->load([], $this->container);

        $definition = $this->container->getDefinition(ResetManager::class);
        self::assertTrue($definition->isPublic());
    }

    /**
     * When symfony-messenger is NOT installed, no transport service should be registered
     * and no error should occur.
     */
    public function testNoErrorWhenMessengerNotInstalled(): void
    {
        // AsyncPlatform\SymfonyMessenger\OpenSwooleTransport does not exist in this test env
        $this->extension->load([], $this->container);

        self::assertFalse($this->container->hasDefinition('async_platform.messenger.transport'));
        self::assertFalse($this->container->hasDefinition('async_platform.messenger.transport_factory'));
    }

    /**
     * When symfony-realtime is NOT installed, no realtime service should be registered.
     */
    public function testNoErrorWhenRealtimeNotInstalled(): void
    {
        $this->extension->load([], $this->container);

        self::assertFalse($this->container->hasDefinition('async_platform.realtime.adapter'));
    }

    /**
     * When symfony-otel is NOT installed, no OTEL service should be registered.
     */
    public function testNoErrorWhenOtelNotInstalled(): void
    {
        $this->extension->load([], $this->container);

        self::assertFalse($this->container->hasDefinition('async_platform.otel.span_factory'));
        self::assertFalse($this->container->hasDefinition('async_platform.otel.request_listener'));
        self::assertFalse($this->container->hasDefinition('async_platform.otel.metrics_exporter'));
    }

    public function testOtelDisabledByConfig(): void
    {
        $this->extension->load([
            ['otel' => ['enabled' => false]],
        ], $this->container);

        // Even if the classes existed, otel.enabled=false should skip registration
        self::assertFalse($this->container->hasDefinition('async_platform.otel.span_factory'));
    }

    public function testMetricsCollectorServiceRegistered(): void
    {
        $this->extension->load([], $this->container);

        self::assertTrue($this->container->hasDefinition('AsyncPlatform\RuntimePack\MetricsCollector'));
    }
}
