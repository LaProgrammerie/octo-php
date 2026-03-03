<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use Octo\RuntimePack\RequestHandler;
use Octo\RuntimePack\ServerBootstrap;
use Octo\RuntimePack\ServerConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Minimal unit tests for ServerBootstrap.
 *
 * ServerBootstrap is the "glue" class that wires OpenSwoole primitives together.
 * It cannot be fully unit tested without the OpenSwoole extension running.
 * All individual components (ServerConfig, JsonLogger, WorkerLifecycle, etc.)
 * are thoroughly unit tested in their own test files.
 *
 * These tests verify:
 * - Class exists and is final
 * - Static run() method has the expected signature
 * - Settings mapping produces correct OpenSwoole settings array
 *
 * Full validation happens in integration tests (Task 15).
 */
final class ServerBootstrapTest extends TestCase
{
    public function testClassExists(): void
    {
        self::assertTrue(class_exists(ServerBootstrap::class));
    }

    public function testClassIsFinal(): void
    {
        $reflection = new ReflectionClass(ServerBootstrap::class);
        self::assertTrue($reflection->isFinal());
    }

    public function testRunMethodExists(): void
    {
        $reflection = new ReflectionClass(ServerBootstrap::class);
        self::assertTrue($reflection->hasMethod('run'));
    }

    public function testRunMethodIsPublicStatic(): void
    {
        $method = new ReflectionMethod(ServerBootstrap::class, 'run');
        self::assertTrue($method->isPublic());
        self::assertTrue($method->isStatic());
    }

    public function testRunMethodReturnTypeIsVoid(): void
    {
        $method = new ReflectionMethod(ServerBootstrap::class, 'run');
        $returnType = $method->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('void', $returnType->getName());
    }

    public function testRunMethodHasExpectedParameters(): void
    {
        $method = new ReflectionMethod(ServerBootstrap::class, 'run');
        $params = $method->getParameters();

        self::assertCount(3, $params);

        // $appHandler: callable
        self::assertSame('appHandler', $params[0]->getName());
        self::assertFalse($params[0]->isOptional());

        // $production: bool, default false
        self::assertSame('production', $params[1]->getName());
        self::assertTrue($params[1]->isOptional());
        self::assertFalse($params[1]->getDefaultValue());

        // $jobRegistrar: ?callable, default null
        self::assertSame('jobRegistrar', $params[2]->getName());
        self::assertTrue($params[2]->isOptional());
        self::assertNull($params[2]->getDefaultValue());
    }

    public function testBuildSettingsProducesCorrectMapping(): void
    {
        // Access private buildSettings via reflection
        $method = new ReflectionMethod(ServerBootstrap::class, 'buildSettings');

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9090,
            workers: 4,
            production: true,
            maxRequestBodySize: 4_194_304,
            maxConnections: 2048,
            maxRequests: 5000,
        );

        $settings = $method->invoke(null, $config);

        // Verify all expected OpenSwoole settings
        self::assertSame(4, $settings['worker_num']);
        self::assertSame(4_194_304, $settings['package_max_length']);
        self::assertSame(2048, $settings['max_connection']);
        self::assertSame(5000, $settings['max_request']);
        self::assertTrue($settings['open_http_protocol']);
        self::assertTrue($settings['http_compression']);
    }

    public function testBuildSettingsWithDefaults(): void
    {
        $method = new ReflectionMethod(ServerBootstrap::class, 'buildSettings');

        $config = new ServerConfig();
        $settings = $method->invoke(null, $config);

        // Default values from ServerConfig
        self::assertSame(0, $settings['worker_num']); // 0 = auto-detect (resolved by factory)
        self::assertSame(2_097_152, $settings['package_max_length']); // 2 MB
        self::assertSame(1024, $settings['max_connection']);
        self::assertSame(10_000, $settings['max_request']);
        self::assertTrue($settings['open_http_protocol']);
        self::assertTrue($settings['http_compression']);
    }

    public function testBuildSettingsDoesNotContainUnsupportedOptions(): void
    {
        $method = new ReflectionMethod(ServerBootstrap::class, 'buildSettings');

        $config = new ServerConfig();
        $settings = $method->invoke(null, $config);

        // http_server_software is not supported in OpenSwoole 26.x
        self::assertArrayNotHasKey('http_server_software', $settings);
    }

    public function testBuildSettingsContainsExactlyExpectedKeys(): void
    {
        $method = new ReflectionMethod(ServerBootstrap::class, 'buildSettings');

        $config = new ServerConfig();
        $settings = $method->invoke(null, $config);

        $expectedKeys = [
            'worker_num',
            'package_max_length',
            'max_connection',
            'max_request',
            'open_http_protocol',
            'http_compression',
        ];

        self::assertSame($expectedKeys, array_keys($settings));
    }

    public function testRunStartupChecksMethodExists(): void
    {
        $reflection = new ReflectionClass(ServerBootstrap::class);
        self::assertTrue($reflection->hasMethod('runStartupChecks'));
    }

    public function testOnWorkerStartMethodExists(): void
    {
        $reflection = new ReflectionClass(ServerBootstrap::class);
        self::assertTrue($reflection->hasMethod('onWorkerStart'));
    }

    public function testOnRequestMethodRemovedInFavorOfRequestHandler(): void
    {
        // onRequest was moved to RequestHandler::handle() (Task 10)
        $reflection = new ReflectionClass(ServerBootstrap::class);
        self::assertFalse($reflection->hasMethod('onRequest'));

        // Verify RequestHandler exists and has handle()
        $rhReflection = new ReflectionClass(RequestHandler::class);
        self::assertTrue($rhReflection->hasMethod('handle'));
    }
}
