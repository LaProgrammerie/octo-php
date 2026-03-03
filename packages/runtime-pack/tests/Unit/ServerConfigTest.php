<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use Octo\RuntimePack\ServerConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerConfigTest extends TestCase
{
    #[Test]
    public function defaults_are_correct(): void
    {
        $config = new ServerConfig();

        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(8080, $config->port);
        self::assertSame(0, $config->workers);
        self::assertFalse($config->production);
        self::assertSame(2_097_152, $config->maxRequestBodySize);
        self::assertSame(1024, $config->maxConnections);
        self::assertSame(60, $config->requestHandlerTimeout);
        self::assertSame(30, $config->shutdownTimeout);
        self::assertSame(10_000, $config->maxRequests);
        self::assertSame(3_600, $config->maxUptime);
        self::assertSame(134_217_728, $config->maxMemoryRss);
        self::assertSame(5, $config->workerRestartMinInterval);
        self::assertSame(4, $config->blockingPoolWorkers);
        self::assertSame(64, $config->blockingPoolQueueSize);
        self::assertSame(30, $config->blockingPoolTimeout);
        self::assertSame(0, $config->maxConcurrentScopes);
        self::assertSame(500.0, $config->eventLoopLagThresholdMs);
    }

    #[Test]
    public function custom_values_are_stored(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9090,
            workers: 8,
            production: true,
            maxRequestBodySize: 4_194_304,
            maxConnections: 2048,
            requestHandlerTimeout: 120,
            shutdownTimeout: 60,
            maxRequests: 50_000,
            maxUptime: 7_200,
            maxMemoryRss: 268_435_456,
            workerRestartMinInterval: 10,
            blockingPoolWorkers: 8,
            blockingPoolQueueSize: 128,
            blockingPoolTimeout: 60,
            maxConcurrentScopes: 50,
            eventLoopLagThresholdMs: 200.0,
        );

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(9090, $config->port);
        self::assertSame(8, $config->workers);
        self::assertTrue($config->production);
        self::assertSame(4_194_304, $config->maxRequestBodySize);
        self::assertSame(2048, $config->maxConnections);
        self::assertSame(120, $config->requestHandlerTimeout);
        self::assertSame(60, $config->shutdownTimeout);
        self::assertSame(50_000, $config->maxRequests);
        self::assertSame(7_200, $config->maxUptime);
        self::assertSame(268_435_456, $config->maxMemoryRss);
        self::assertSame(10, $config->workerRestartMinInterval);
        self::assertSame(8, $config->blockingPoolWorkers);
        self::assertSame(128, $config->blockingPoolQueueSize);
        self::assertSame(60, $config->blockingPoolTimeout);
        self::assertSame(50, $config->maxConcurrentScopes);
        self::assertSame(200.0, $config->eventLoopLagThresholdMs);
    }
}
