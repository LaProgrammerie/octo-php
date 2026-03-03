<?php

declare(strict_types=1);

namespace Octo\SymfonyBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Loads the octo configuration and registers core bridge services.
 *
 * Responsibilities:
 * - Load services.yaml with core bridge service definitions
 * - Map YAML config to container parameters (OCTOP_SYMFONY_* env vars)
 * - Auto-register RequestIdProcessor as Monolog processor if Monolog is available
 * - Auto-detect optional packages via class_exists() and register their services
 */
final class OctoExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Map config to container parameters
        $container->setParameter('octo.memory_warning_threshold', $config['memory_warning_threshold']);
        $container->setParameter('octo.reset_warning_ms', $config['reset_warning_ms']);
        $container->setParameter('octo.kernel_reboot_every', $config['kernel_reboot_every']);

        // Messenger config
        $container->setParameter('octo.messenger.channel_capacity', $config['messenger']['channel_capacity']);
        $container->setParameter('octo.messenger.consumers', $config['messenger']['consumers']);
        $container->setParameter('octo.messenger.send_timeout', $config['messenger']['send_timeout']);

        // Realtime config
        $container->setParameter('octo.realtime.ws_max_lifetime_seconds', $config['realtime']['ws_max_lifetime_seconds']);

        // OTEL config
        $container->setParameter('octo.otel.enabled', $config['otel']['enabled']);

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
        if (\class_exists(\Monolog\Logger::class) && $container->hasDefinition('Octo\SymfonyBridge\RequestIdProcessor')) {
            $definition = $container->getDefinition('Octo\SymfonyBridge\RequestIdProcessor');
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
        if (!\class_exists('Octo\SymfonyMessenger\OpenSwooleTransport')) {
            return;
        }

        $container->register('octo.messenger.transport', 'Octo\SymfonyMessenger\OpenSwooleTransport')
            ->setArguments([
                $config['messenger']['channel_capacity'],
                $config['messenger']['send_timeout'],
                null, // logger injected via setter or autowiring
            ])
            ->setPublic(false);

        if (\class_exists('Octo\SymfonyMessenger\OpenSwooleTransportFactory')) {
            $container->register('octo.messenger.transport_factory', 'Octo\SymfonyMessenger\OpenSwooleTransportFactory')
                ->addTag('messenger.transport_factory')
                ->setPublic(false);
        }
    }

    /**
     * If symfony-realtime is installed, register WebSocketHandler + SSE helpers.
     */
    private function configureRealtime(ContainerBuilder $container, array $config): void
    {
        if (!\class_exists('Octo\SymfonyRealtime\RealtimeServerAdapter')) {
            return;
        }

        $container->register('octo.realtime.adapter', 'Octo\SymfonyRealtime\RealtimeServerAdapter')
            ->setArguments([
                $container->hasDefinition('Octo\SymfonyBridge\HttpKernelAdapter')
                ? $container->getDefinition('Octo\SymfonyBridge\HttpKernelAdapter')
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

        if (!\class_exists('Octo\SymfonyOtel\OtelSpanFactory')) {
            return;
        }

        $container->register('octo.otel.span_factory', 'Octo\SymfonyOtel\OtelSpanFactory')
            ->setPublic(false);

        if (\class_exists('Octo\SymfonyOtel\OtelRequestListener')) {
            $container->register('octo.otel.request_listener', 'Octo\SymfonyOtel\OtelRequestListener')
                ->setPublic(false);
        }

        if (\class_exists('Octo\SymfonyOtel\OtelMetricsExporter')) {
            $container->register('octo.otel.metrics_exporter', 'Octo\SymfonyOtel\OtelMetricsExporter')
                ->setPublic(false);
        }
    }
}
