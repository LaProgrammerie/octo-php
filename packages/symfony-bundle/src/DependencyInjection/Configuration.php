<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration tree for the async_platform bundle.
 *
 * async_platform:
 *   memory_warning_threshold: 104857600  # 100 MB default
 *   reset_warning_ms: 50                 # reset duration warning threshold
 *   kernel_reboot_every: 0               # 0 = disabled
 *   messenger:
 *     channel_capacity: 100
 *     consumers: 1
 *     send_timeout: 5.0
 *   realtime:
 *     ws_max_lifetime_seconds: 3600
 *   otel:
 *     enabled: true
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('async_platform');

        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
            ->integerNode('memory_warning_threshold')
            ->defaultValue(104_857_600) // 100 MB
            ->min(0)
            ->info('Memory RSS warning threshold in bytes (default: 100 MB)')
            ->end()
            ->integerNode('reset_warning_ms')
            ->defaultValue(50)
            ->min(0)
            ->info('Reset duration warning threshold in milliseconds')
            ->end()
            ->integerNode('kernel_reboot_every')
            ->defaultValue(0)
            ->min(0)
            ->info('Reboot kernel every N requests (0 = disabled)')
            ->end()
            ->arrayNode('messenger')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('channel_capacity')
            ->defaultValue(100)
            ->min(1)
            ->info('OpenSwoole channel capacity for Messenger transport')
            ->end()
            ->integerNode('consumers')
            ->defaultValue(1)
            ->min(1)
            ->info('Number of consumer coroutines')
            ->end()
            ->floatNode('send_timeout')
            ->defaultValue(5.0)
            ->min(0.0)
            ->info('Send timeout in seconds when channel is full')
            ->end()
            ->end()
            ->end()
            ->arrayNode('realtime')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('ws_max_lifetime_seconds')
            ->defaultValue(3600)
            ->min(0)
            ->info('Maximum WebSocket connection lifetime in seconds')
            ->end()
            ->end()
            ->end()
            ->arrayNode('otel')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->defaultTrue()
            ->info('Enable OpenTelemetry integration when symfony-otel is installed')
            ->end()
            ->end()
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
