<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBundle\Tests\Unit;

use AsyncPlatform\SymfonyBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * Tests that the async_platform configuration tree is loaded correctly
 * with defaults and custom values.
 */
final class ConfigurationTest extends TestCase
{
    private Processor $processor;
    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->configuration = new Configuration();
    }

    public function testDefaultValues(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, []);

        self::assertSame(104_857_600, $config['memory_warning_threshold']);
        self::assertSame(50, $config['reset_warning_ms']);
        self::assertSame(0, $config['kernel_reboot_every']);

        // Messenger defaults
        self::assertSame(100, $config['messenger']['channel_capacity']);
        self::assertSame(1, $config['messenger']['consumers']);
        self::assertSame(5.0, $config['messenger']['send_timeout']);

        // Realtime defaults
        self::assertSame(3600, $config['realtime']['ws_max_lifetime_seconds']);

        // OTEL defaults
        self::assertTrue($config['otel']['enabled']);
    }

    public function testCustomValues(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'memory_warning_threshold' => 209_715_200,
                'reset_warning_ms' => 100,
                'kernel_reboot_every' => 500,
                'messenger' => [
                    'channel_capacity' => 200,
                    'consumers' => 4,
                    'send_timeout' => 10.0,
                ],
                'realtime' => [
                    'ws_max_lifetime_seconds' => 7200,
                ],
                'otel' => [
                    'enabled' => false,
                ],
            ],
        ]);

        self::assertSame(209_715_200, $config['memory_warning_threshold']);
        self::assertSame(100, $config['reset_warning_ms']);
        self::assertSame(500, $config['kernel_reboot_every']);
        self::assertSame(200, $config['messenger']['channel_capacity']);
        self::assertSame(4, $config['messenger']['consumers']);
        self::assertSame(10.0, $config['messenger']['send_timeout']);
        self::assertSame(7200, $config['realtime']['ws_max_lifetime_seconds']);
        self::assertFalse($config['otel']['enabled']);
    }

    public function testPartialOverride(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['reset_warning_ms' => 75],
        ]);

        // Overridden value
        self::assertSame(75, $config['reset_warning_ms']);

        // Defaults preserved
        self::assertSame(104_857_600, $config['memory_warning_threshold']);
        self::assertSame(0, $config['kernel_reboot_every']);
    }

    public function testConfigTreeRootName(): void
    {
        $tree = $this->configuration->getConfigTreeBuilder()->buildTree();

        self::assertSame('async_platform', $tree->getName());
    }
}
