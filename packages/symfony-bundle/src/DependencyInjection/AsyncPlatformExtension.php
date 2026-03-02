<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Loads the async_platform configuration and registers core bridge services.
 *
 * Responsibilities:
 * - Load services.yaml with core bridge service definitions
 * - Map YAML config to container parameters (ASYNC_PLATFORM_SYMFONY_* env vars)
 * - Auto-register RequestIdProcessor as Monolog processor if Monolog is available
 * - Auto-detect optional packages via class_exists() and register their services
 */
final class AsyncPlatformExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Map config to container parameters
        $container->setParameter('async_platform.memory_warning_threshold', $config['memory_warning_threshold']);
        $container->setParameter('async_platform.reset_warning_ms', $config['reset_warning_ms']);
        $container->setParameter('async_platform.kernel_reboot_every', $config['kernel_reboot_every']);

        // Messenger config
        $container->setParameter('async_platform.messenger.channel_capacity', $config['messenger']['channel_capacity']);
        $container->setParameter('async_platform.messenger.consumers', $config['messenger']['consumers']);
        $container->setParameter('async_platform.messenger.send_timeout', $config['messenger']['send_timeout']);

        // Realtime config
        $container->setParameter('async_platform.realtime.ws_max_lifetime_seconds', $config['realtime']['ws_max_lifetime_seconds']);

        // OTEL config
        $container->setParameter('async_platform.otel.enabled', $config['otel']['enabled']);

        // Load service definitions
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config'),
        );
        $loader->load('services.yaml');

        // Configure core services
        $this->configureCore($container, $config);

        // Auto-detect optional packages
        $this->configureMessenger($container, $config);
        $this->configureRealtime($container, $config);
        $this->configureOtel($container, $config);
    }

    private function configureCore(ContainerBuilder $container, array $config): void
    {
        // Auto-register RequestIdProcessor as Monolog processor if Monolog is available
        if (\class_exists(\Monolog\Logger::class) && $container->hasDefinition('AsyncPlatform\SymfonyBridge\RequestIdProcessor')) {
            $definition = $container->getDefinition('AsyncPlatform\SymfonyBridge\RequestIdProcessor');
            if (!$definition->hasTag('monolog.processor')) {
                $definition->addTag('monolog.processor');
            }
        }
    }

    /**
     * If symfony-messenger is installed, register OpenSwooleTransport + factory.
     */
    private function configureMessenger(ContainerBuilder $container, array $config): void
    {
        if (!\class_exists('AsyncPlatform\SymfonyMessenger\OpenSwooleTransport')) {
            return;
        }

        $container->register('async_platform.messenger.transport', 'AsyncPlatform\SymfonyMessenger\OpenSwooleTransport')
            ->setArguments([
                $config['messenger']['channel_capacity'],
                $config['messenger']['send_timeout'],
                null, // logger injected via setter or autowiring
            ])
            ->setPublic(false);

        if (\class_exists('AsyncPlatform\SymfonyMessenger\OpenSwooleTransportFactory')) {
            $container->register('async_platform.messenger.transport_factory', 'AsyncPlatform\SymfonyMessenger\OpenSwooleTransportFactory')
                ->addTag('messenger.transport_factory')
                ->setPublic(false);
        }
    }

    /**
     * If symfony-realtime is installed, register WebSocketHandler + SSE helpers.
     */
    private function configureRealtime(ContainerBuilder $container, array $config): void
    {
        if (!\class_exists('AsyncPlatform\SymfonyRealtime\RealtimeServerAdapter')) {
            return;
        }

        $container->register('async_platform.realtime.adapter', 'AsyncPlatform\SymfonyRealtime\RealtimeServerAdapter')
            ->setArguments([
                $container->hasDefinition('AsyncPlatform\SymfonyBridge\HttpKernelAdapter')
                ? $container->getDefinition('AsyncPlatform\SymfonyBridge\HttpKernelAdapter')
                : null,
                null, // WebSocketHandler — provided by the application
                null, // logger
            ])
            ->setPublic(true);
    }

    /**
     * If symfony-otel is installed, configure span processor + metrics exporter.
     */
    private function configureOtel(ContainerBuilder $container, array $config): void
    {
        if (!$config['otel']['enabled']) {
            return;
        }

        if (!\class_exists('AsyncPlatform\SymfonyOtel\OtelSpanFactory')) {
            return;
        }

        $container->register('async_platform.otel.span_factory', 'AsyncPlatform\SymfonyOtel\OtelSpanFactory')
            ->setPublic(false);

        if (\class_exists('AsyncPlatform\SymfonyOtel\OtelRequestListener')) {
            $container->register('async_platform.otel.request_listener', 'AsyncPlatform\SymfonyOtel\OtelRequestListener')
                ->setPublic(false);
        }

        if (\class_exists('AsyncPlatform\SymfonyOtel\OtelMetricsExporter')) {
            $container->register('async_platform.otel.metrics_exporter', 'AsyncPlatform\SymfonyOtel\OtelMetricsExporter')
                ->setPublic(false);
        }
    }
}
