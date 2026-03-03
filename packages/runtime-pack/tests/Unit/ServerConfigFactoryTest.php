<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use Octo\RuntimePack\Exception\ConfigValidationException;
use Octo\RuntimePack\ServerConfigFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerConfigFactoryTest extends TestCase
{
    /**
     * Env vars set by this test — cleaned up in tearDown.
     *
     * @var list<string>
     */
    private array $envVarsSet = [];

    protected function tearDown(): void
    {
        foreach ($this->envVarsSet as $var) {
            putenv($var);
        }
        $this->envVarsSet = [];
    }

    // ── Defaults ──

    #[Test]
    public function devDefaults(): void
    {
        $this->clearAllEnvVars();

        $result = ServerConfigFactory::fromEnvironment(production: false);
        $config = $result['config'];

        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(8080, $config->port);
        self::assertSame(2, $config->workers); // dev default
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
        self::assertEmpty($result['warnings']);
    }

    #[Test]
    public function prodDefaultsUseCpuCount(): void
    {
        $this->clearAllEnvVars();

        $result = ServerConfigFactory::fromEnvironment(
            production: true,
            cpuCountResolver: static fn (): int => 16,
        );
        $config = $result['config'];

        self::assertSame(16, $config->workers);
        self::assertTrue($config->production);
        self::assertEmpty($result['warnings']);
    }

    // ── Custom values ──

    #[Test]
    public function readsAllEnvVars(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_HOST', '127.0.0.1');
        $this->setEnv('APP_PORT', '9090');
        $this->setEnv('APP_WORKERS', '8');
        $this->setEnv('MAX_REQUEST_BODY_SIZE', '4194304');
        $this->setEnv('MAX_CONNECTIONS', '2048');
        $this->setEnv('REQUEST_HANDLER_TIMEOUT', '120');
        $this->setEnv('SHUTDOWN_TIMEOUT', '60');
        $this->setEnv('MAX_REQUESTS', '50000');
        $this->setEnv('MAX_UPTIME', '7200');
        $this->setEnv('MAX_MEMORY_RSS', '268435456');
        $this->setEnv('WORKER_RESTART_MIN_INTERVAL', '10');
        $this->setEnv('BLOCKING_POOL_WORKERS', '8');
        $this->setEnv('BLOCKING_POOL_QUEUE_SIZE', '128');
        $this->setEnv('BLOCKING_POOL_TIMEOUT', '60');
        $this->setEnv('MAX_CONCURRENT_SCOPES', '50');
        $this->setEnv('EVENT_LOOP_LAG_THRESHOLD_MS', '200');

        $result = ServerConfigFactory::fromEnvironment(production: true);
        $config = $result['config'];

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(9090, $config->port);
        self::assertSame(8, $config->workers); // explicit override
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

    // ── resolveWorkers ──

    #[Test]
    public function workersZeroDevResolvesToTwo(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_WORKERS', '0');

        $config = ServerConfigFactory::fromEnvironment(production: false)['config'];

        self::assertSame(2, $config->workers);
    }

    #[Test]
    public function workersZeroProdResolvesToCpuCount(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_WORKERS', '0');

        $config = ServerConfigFactory::fromEnvironment(
            production: true,
            cpuCountResolver: static fn (): int => 4,
        )['config'];

        self::assertSame(4, $config->workers);
    }

    #[Test]
    public function workersExplicitOverridesAuto(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_WORKERS', '12');

        $config = ServerConfigFactory::fromEnvironment(
            production: true,
            cpuCountResolver: static fn (): int => 4,
        )['config'];

        self::assertSame(12, $config->workers);
    }

    // ── Validation errors ──

    #[Test]
    public function invalidPortZero(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_PORT', '0');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('APP_PORT', $e->errors);

            throw $e;
        }
    }

    #[Test]
    public function invalidPortTooHigh(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_PORT', '99999');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('APP_PORT', $e->errors);

            throw $e;
        }
    }

    #[Test]
    public function invalidPortNonNumeric(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_PORT', 'abc');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('APP_PORT', $e->errors);
            self::assertStringContainsString('must be an integer', $e->errors['APP_PORT']);

            throw $e;
        }
    }

    #[Test]
    public function invalidWorkersNegative(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_WORKERS', '-1');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('APP_WORKERS', $e->errors);

            throw $e;
        }
    }

    #[Test]
    public function invalidPortFloat(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_PORT', '3.14');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('APP_PORT', $e->errors);

            throw $e;
        }
    }

    #[Test]
    public function collectsMultipleErrors(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_PORT', '0');
        $this->setEnv('APP_WORKERS', '-5');
        $this->setEnv('MAX_CONNECTIONS', '-1');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertCount(3, $e->errors);
            self::assertArrayHasKey('APP_PORT', $e->errors);
            self::assertArrayHasKey('APP_WORKERS', $e->errors);
            self::assertArrayHasKey('MAX_CONNECTIONS', $e->errors);

            throw $e;
        }
    }

    #[Test]
    public function invalidEventLoopLagThresholdNegative(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('EVENT_LOOP_LAG_THRESHOLD_MS', '-10');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('EVENT_LOOP_LAG_THRESHOLD_MS', $e->errors);

            throw $e;
        }
    }

    #[Test]
    public function invalidEventLoopLagThresholdNonNumeric(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('EVENT_LOOP_LAG_THRESHOLD_MS', 'fast');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('EVENT_LOOP_LAG_THRESHOLD_MS', $e->errors);
            self::assertStringContainsString('must be a number', $e->errors['EVENT_LOOP_LAG_THRESHOLD_MS']);

            throw $e;
        }
    }

    #[Test]
    public function eventLoopLagThresholdZeroIsValid(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('EVENT_LOOP_LAG_THRESHOLD_MS', '0');

        $config = ServerConfigFactory::fromEnvironment()['config'];

        self::assertSame(0.0, $config->eventLoopLagThresholdMs);
    }

    #[Test]
    public function maxConcurrentScopesZeroIsValid(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('MAX_CONCURRENT_SCOPES', '0');

        $config = ServerConfigFactory::fromEnvironment()['config'];

        self::assertSame(0, $config->maxConcurrentScopes);
    }

    #[Test]
    public function invalidMaxConcurrentScopesNegative(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('MAX_CONCURRENT_SCOPES', '-1');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('MAX_CONCURRENT_SCOPES', $e->errors);

            throw $e;
        }
    }

    #[Test]
    public function invalidBlockingPoolQueueSizeZero(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('BLOCKING_POOL_QUEUE_SIZE', '0');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('BLOCKING_POOL_QUEUE_SIZE', $e->errors);

            throw $e;
        }
    }

    #[Test]
    public function invalidRequestHandlerTimeoutZero(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('REQUEST_HANDLER_TIMEOUT', '0');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('REQUEST_HANDLER_TIMEOUT', $e->errors);

            throw $e;
        }
    }

    #[Test]
    public function invalidShutdownTimeoutZero(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('SHUTDOWN_TIMEOUT', '0');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('SHUTDOWN_TIMEOUT', $e->errors);

            throw $e;
        }
    }

    #[Test]
    public function invalidMaxRequestBodySizeZero(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('MAX_REQUEST_BODY_SIZE', '0');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('MAX_REQUEST_BODY_SIZE', $e->errors);

            throw $e;
        }
    }

    // ── Prod warning: all reload policies disabled ──

    #[Test]
    public function prodWarningWhenAllReloadPoliciesDisabled(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('MAX_REQUESTS', '0');
        $this->setEnv('MAX_UPTIME', '0');
        $this->setEnv('MAX_MEMORY_RSS', '0');

        $result = ServerConfigFactory::fromEnvironment(
            production: true,
            cpuCountResolver: static fn (): int => 2,
        );

        self::assertNotEmpty($result['warnings']);
        self::assertStringContainsString('reload policies', $result['warnings'][0]);
    }

    #[Test]
    public function noWarningWhenAtLeastOneReloadPolicyActive(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('MAX_REQUESTS', '0');
        $this->setEnv('MAX_UPTIME', '0');
        // MAX_MEMORY_RSS defaults to 134217728 (active)

        $result = ServerConfigFactory::fromEnvironment(
            production: true,
            cpuCountResolver: static fn (): int => 2,
        );

        self::assertEmpty($result['warnings']);
    }

    #[Test]
    public function noWarningInDevModeEvenIfAllDisabled(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('MAX_REQUESTS', '0');
        $this->setEnv('MAX_UPTIME', '0');
        $this->setEnv('MAX_MEMORY_RSS', '0');

        $result = ServerConfigFactory::fromEnvironment(production: false);

        self::assertEmpty($result['warnings']);
    }

    // ── Edge cases ──

    #[Test]
    public function maxRequestsZeroIsValidDisabled(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('MAX_REQUESTS', '0');

        $config = ServerConfigFactory::fromEnvironment()['config'];

        self::assertSame(0, $config->maxRequests);
    }

    #[Test]
    public function maxUptimeZeroIsValidDisabled(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('MAX_UPTIME', '0');

        $config = ServerConfigFactory::fromEnvironment()['config'];

        self::assertSame(0, $config->maxUptime);
    }

    #[Test]
    public function maxMemoryRssZeroIsValidDisabled(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('MAX_MEMORY_RSS', '0');

        $config = ServerConfigFactory::fromEnvironment()['config'];

        self::assertSame(0, $config->maxMemoryRss);
    }

    #[Test]
    public function portBoundaryValues(): void
    {
        $this->clearAllEnvVars();

        // Port 1 — valid
        $this->setEnv('APP_PORT', '1');
        $config = ServerConfigFactory::fromEnvironment()['config'];
        self::assertSame(1, $config->port);

        // Port 65535 — valid
        $this->setEnv('APP_PORT', '65535');
        $config = ServerConfigFactory::fromEnvironment()['config'];
        self::assertSame(65535, $config->port);
    }

    #[Test]
    public function invalidMaxConcurrentScopesNonNumeric(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('MAX_CONCURRENT_SCOPES', 'unlimited');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('MAX_CONCURRENT_SCOPES', $e->errors);
            self::assertStringContainsString('must be an integer', $e->errors['MAX_CONCURRENT_SCOPES']);

            throw $e;
        }
    }

    #[Test]
    public function eventLoopLagThresholdFloatValue(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('EVENT_LOOP_LAG_THRESHOLD_MS', '123.456');

        $config = ServerConfigFactory::fromEnvironment()['config'];

        self::assertSame(123.456, $config->eventLoopLagThresholdMs);
    }

    #[Test]
    public function maxConcurrentScopesPositiveValue(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('MAX_CONCURRENT_SCOPES', '100');

        $config = ServerConfigFactory::fromEnvironment()['config'];

        self::assertSame(100, $config->maxConcurrentScopes);
    }

    #[Test]
    public function workersZeroProdWithoutResolverUsesDefault(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_WORKERS', '0');

        // Without cpuCountResolver, prod mode would call swoole_cpu_num()
        // We provide a resolver to avoid the dependency
        $config = ServerConfigFactory::fromEnvironment(
            production: true,
            cpuCountResolver: static fn (): int => 8,
        )['config'];

        self::assertSame(8, $config->workers);
    }

    #[Test]
    public function invalidPortNegative(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_PORT', '-1');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('APP_PORT', $e->errors);

            throw $e;
        }
    }

    #[Test]
    public function invalidPort65536(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('APP_PORT', '65536');

        $this->expectException(ConfigValidationException::class);

        try {
            ServerConfigFactory::fromEnvironment();
        } catch (ConfigValidationException $e) {
            self::assertArrayHasKey('APP_PORT', $e->errors);
            self::assertStringContainsString('must be between 1 and 65535', $e->errors['APP_PORT']);

            throw $e;
        }
    }

    #[Test]
    public function prodWarningMessageMentionsAllThreeVariables(): void
    {
        $this->clearAllEnvVars();
        $this->setEnv('MAX_REQUESTS', '0');
        $this->setEnv('MAX_UPTIME', '0');
        $this->setEnv('MAX_MEMORY_RSS', '0');

        $result = ServerConfigFactory::fromEnvironment(
            production: true,
            cpuCountResolver: static fn (): int => 2,
        );

        $warning = $result['warnings'][0];
        self::assertStringContainsString('MAX_REQUESTS=0', $warning);
        self::assertStringContainsString('MAX_UPTIME=0', $warning);
        self::assertStringContainsString('MAX_MEMORY_RSS=0', $warning);
    }

    private function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $this->envVarsSet[] = $name;
    }

    private function clearAllEnvVars(): void
    {
        $vars = [
            'APP_HOST',
            'APP_PORT',
            'APP_WORKERS',
            'MAX_REQUEST_BODY_SIZE',
            'MAX_CONNECTIONS',
            'REQUEST_HANDLER_TIMEOUT',
            'SHUTDOWN_TIMEOUT',
            'MAX_REQUESTS',
            'MAX_UPTIME',
            'MAX_MEMORY_RSS',
            'WORKER_RESTART_MIN_INTERVAL',
            'BLOCKING_POOL_WORKERS',
            'BLOCKING_POOL_QUEUE_SIZE',
            'BLOCKING_POOL_TIMEOUT',
            'MAX_CONCURRENT_SCOPES',
            'EVENT_LOOP_LAG_THRESHOLD_MS',
        ];
        foreach ($vars as $var) {
            putenv($var);
            $this->envVarsSet[] = $var;
        }
    }
}
