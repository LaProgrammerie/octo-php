<?php

declare(strict_types=1);

namespace Octo\SymfonyBundle\Tests\Unit;

use Octo\SymfonyBridge\HttpKernelAdapter;
use Octo\SymfonyBridge\MetricsBridge;
use Octo\SymfonyBridge\RequestIdProcessor;
use Octo\SymfonyBridge\ResetManager;
use Octo\SymfonyBundle\DependencyInjection\OctoExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests that the OctoExtension correctly loads configuration,
 * registers core bridge services, and handles optional package auto-detection.
 */
final class OctoExtensionTest extends TestCase
{
    private ContainerBuilder $container;
    private OctoExtension $extension;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->extension = new OctoExtension();
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

        self::assertSame(104_857_600, $this->container->getParameter('octo.memory_warning_threshold'));
        self::assertSame(50, $this->container->getParameter('octo.reset_warning_ms'));
        self::assertSame(0, $this->container->getParameter('octo.kernel_reboot_every'));
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

        self::assertSame(200_000_000, $this->container->getParameter('octo.memory_warning_threshold'));
        self::assertSame(100, $this->container->getParameter('octo.reset_warning_ms'));
        self::assertSame(1000, $this->container->getParameter('octo.kernel_reboot_every'));
    }

    public function testMessengerParametersAreSet(): void
    {
        $this->extension->load([], $this->container);

        self::assertSame(100, $this->container->getParameter('octo.messenger.channel_capacity'));
        self::assertSame(1, $this->container->getParameter('octo.messenger.consumers'));
        self::assertSame(5.0, $this->container->getParameter('octo.messenger.send_timeout'));
    }

    public function testRealtimeParametersAreSet(): void
    {
        $this->extension->load([], $this->container);

        self::assertSame(3600, $this->container->getParameter('octo.realtime.ws_max_lifetime_seconds'));
    }

    public function testOtelParametersAreSet(): void
    {
        $this->extension->load([], $this->container);

        self::assertTrue($this->container->getParameter('octo.otel.enabled'));
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
     * When symfony-messenger IS available (monorepo autoload), transport services
     * should be registered. Skipped if the class is not autoloadable.
     */
    public function testMessengerServicesRegisteredWhenAvailable(): void
    {
        if (!class_exists('Octo\SymfonyMessenger\OpenSwooleTransport')) {
            self::markTestSkipped('symfony-messenger package not autoloaded');
        }

        $this->extension->load([], $this->container);

        self::assertTrue($this->container->hasDefinition('octo.messenger.transport'));
    }

    /**
     * When symfony-realtime IS available (monorepo autoload), realtime adapter
     * should be registered. Skipped if the class is not autoloadable.
     */
    public function testRealtimeServicesRegisteredWhenAvailable(): void
    {
        if (!class_exists('Octo\SymfonyRealtime\RealtimeServerAdapter')) {
            self::markTestSkipped('symfony-realtime package not autoloaded');
        }

        $this->extension->load([], $this->container);

        self::assertTrue($this->container->hasDefinition('octo.realtime.adapter'));
    }

    /**
     * When symfony-otel IS available (monorepo autoload), OTEL services
     * should be registered. Skipped if the class is not autoloadable.
     */
    public function testOtelServicesRegisteredWhenAvailable(): void
    {
        if (!class_exists('Octo\SymfonyOtel\OtelSpanFactory')) {
            self::markTestSkipped('symfony-otel package not autoloaded');
        }

        $this->extension->load([], $this->container);

        self::assertTrue($this->container->hasDefinition('octo.otel.span_factory'));
    }

    public function testOtelDisabledByConfig(): void
    {
        $this->extension->load([
            ['otel' => ['enabled' => false]],
        ], $this->container);

        // Even if the classes existed, otel.enabled=false should skip registration
        self::assertFalse($this->container->hasDefinition('octo.otel.span_factory'));
    }

    public function testMetricsCollectorServiceRegistered(): void
    {
        $this->extension->load([], $this->container);

        self::assertTrue($this->container->hasDefinition('Octo\RuntimePack\MetricsCollector'));
    }
}
