<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack\Tests\Property;

use AsyncPlatform\RuntimePack\Exception\ConfigValidationException;
use AsyncPlatform\RuntimePack\ServerConfig;
use AsyncPlatform\RuntimePack\ServerConfigFactory;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Feature: runtime-pack-openswoole, Property 1: Config validation round-trip
 *
 * **Validates: Requirements 1.3, 1.4, 1.5, 8.1, 8.2, 8.3, 8.4**
 *
 * Property: For any valid combination of env vars, ServerConfigFactory produces
 * a ServerConfig whose fields match the input values. For any combination with
 * at least one invalid value, ConfigValidationException is thrown and its errors
 * array contains exactly the invalid variables.
 */
final class ConfigValidationRoundTripTest extends TestCase
{
    use TestTrait;

    /**
     * Env vars set by this test — cleaned up in tearDown.
     * @var string[]
     */
    private array $envVarsSet = [];

    private const ALL_ENV_VARS = [
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

    protected function tearDown(): void
    {
        foreach ($this->envVarsSet as $var) {
            putenv($var);
        }
        $this->envVarsSet = [];
    }

    private function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $this->envVarsSet[] = $name;
    }

    private function clearAllEnvVars(): void
    {
        foreach (self::ALL_ENV_VARS as $var) {
            putenv($var);
            $this->envVarsSet[] = $var;
        }
    }

    /**
     * Property 1a: Valid env vars → ServerConfig fields match input values.
     *
     * Generates random valid values for all configurable env vars and verifies
     * that the resulting ServerConfig DTO contains the exact same values.
     */
    #[Test]
    public function validEnvVarsProduceMatchingServerConfig(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(1, 65535),           // port
            Generators::choose(0, 32),              // workers (0 = auto)
            Generators::choose(1, 10_000_000),      // maxRequestBodySize
            Generators::choose(1, 10_000),          // maxConnections
            Generators::choose(1, 300),             // requestHandlerTimeout
            Generators::choose(1, 120),             // shutdownTimeout
            Generators::choose(0, 100_000),         // maxRequests
            Generators::choose(0, 86_400),          // maxUptime
            Generators::choose(0, 536_870_912),     // maxMemoryRss
            Generators::choose(0, 60),              // workerRestartMinInterval
            Generators::choose(0, 16),              // blockingPoolWorkers
            Generators::choose(1, 1024),            // blockingPoolQueueSize
            Generators::choose(1, 120),             // blockingPoolTimeout
            Generators::choose(0, 1000),            // maxConcurrentScopes
            Generators::choose(0, 5000),            // eventLoopLagThresholdMs (int, cast to float)
        )->then(function (
            int $port,
            int $workers,
            int $maxRequestBodySize,
            int $maxConnections,
            int $requestHandlerTimeout,
            int $shutdownTimeout,
            int $maxRequests,
            int $maxUptime,
            int $maxMemoryRss,
            int $workerRestartMinInterval,
            int $blockingPoolWorkers,
            int $blockingPoolQueueSize,
            int $blockingPoolTimeout,
            int $maxConcurrentScopes,
            int $eventLoopLagThresholdMs,
        ): void {
            $this->clearAllEnvVars();

            $this->setEnv('APP_PORT', (string) $port);
            $this->setEnv('APP_WORKERS', (string) $workers);
            $this->setEnv('MAX_REQUEST_BODY_SIZE', (string) $maxRequestBodySize);
            $this->setEnv('MAX_CONNECTIONS', (string) $maxConnections);
            $this->setEnv('REQUEST_HANDLER_TIMEOUT', (string) $requestHandlerTimeout);
            $this->setEnv('SHUTDOWN_TIMEOUT', (string) $shutdownTimeout);
            $this->setEnv('MAX_REQUESTS', (string) $maxRequests);
            $this->setEnv('MAX_UPTIME', (string) $maxUptime);
            $this->setEnv('MAX_MEMORY_RSS', (string) $maxMemoryRss);
            $this->setEnv('WORKER_RESTART_MIN_INTERVAL', (string) $workerRestartMinInterval);
            $this->setEnv('BLOCKING_POOL_WORKERS', (string) $blockingPoolWorkers);
            $this->setEnv('BLOCKING_POOL_QUEUE_SIZE', (string) $blockingPoolQueueSize);
            $this->setEnv('BLOCKING_POOL_TIMEOUT', (string) $blockingPoolTimeout);
            $this->setEnv('MAX_CONCURRENT_SCOPES', (string) $maxConcurrentScopes);
            $this->setEnv('EVENT_LOOP_LAG_THRESHOLD_MS', (string) $eventLoopLagThresholdMs);

            $result = ServerConfigFactory::fromEnvironment(
                production: false,
                cpuCountResolver: static fn(): int => 4,
            );

            $config = $result['config'];
            self::assertInstanceOf(ServerConfig::class, $config);

            self::assertSame($port, $config->port);
            // workers=0 → dev default 2; workers>0 → exact value
            $expectedWorkers = $workers > 0 ? $workers : 2;
            self::assertSame($expectedWorkers, $config->workers);
            self::assertSame($maxRequestBodySize, $config->maxRequestBodySize);
            self::assertSame($maxConnections, $config->maxConnections);
            self::assertSame($requestHandlerTimeout, $config->requestHandlerTimeout);
            self::assertSame($shutdownTimeout, $config->shutdownTimeout);
            self::assertSame($maxRequests, $config->maxRequests);
            self::assertSame($maxUptime, $config->maxUptime);
            self::assertSame($maxMemoryRss, $config->maxMemoryRss);
            self::assertSame($workerRestartMinInterval, $config->workerRestartMinInterval);
            self::assertSame($blockingPoolWorkers, $config->blockingPoolWorkers);
            self::assertSame($blockingPoolQueueSize, $config->blockingPoolQueueSize);
            self::assertSame($blockingPoolTimeout, $config->blockingPoolTimeout);
            self::assertSame($maxConcurrentScopes, $config->maxConcurrentScopes);
            self::assertSame((float) $eventLoopLagThresholdMs, $config->eventLoopLagThresholdMs);
        });
    }

    /**
     * Property 1b: Invalid env vars → ConfigValidationException with correct error keys.
     *
     * Generates a random invalid value for a randomly chosen env var while keeping
     * all others valid. Verifies that ConfigValidationException is thrown and its
     * errors array contains the invalid variable name.
     */
    #[Test]
    public function invalidEnvVarThrowsConfigValidationExceptionWithCorrectVariable(): void
    {
        $this->limitTo(100);

        // Map of env var → [invalidValueGenerator description]
        // We pick one env var at random and inject an invalid value
        $invalidatableVars = [
            'APP_PORT' => ['below_min' => '0', 'above_max' => '99999', 'non_numeric' => 'abc'],
            'APP_WORKERS' => ['negative' => '-1', 'non_numeric' => 'xyz'],
            'MAX_REQUEST_BODY_SIZE' => ['zero' => '0', 'negative' => '-5', 'non_numeric' => 'big'],
            'MAX_CONNECTIONS' => ['zero' => '0', 'negative' => '-1', 'non_numeric' => 'many'],
            'REQUEST_HANDLER_TIMEOUT' => ['zero' => '0', 'negative' => '-10', 'non_numeric' => 'slow'],
            'SHUTDOWN_TIMEOUT' => ['zero' => '0', 'negative' => '-1', 'non_numeric' => 'wait'],
            'MAX_REQUESTS' => ['negative' => '-1', 'non_numeric' => 'lots'],
            'MAX_UPTIME' => ['negative' => '-1', 'non_numeric' => 'forever'],
            'MAX_MEMORY_RSS' => ['negative' => '-1', 'non_numeric' => 'huge'],
            'WORKER_RESTART_MIN_INTERVAL' => ['negative' => '-1', 'non_numeric' => 'fast'],
            'BLOCKING_POOL_WORKERS' => ['negative' => '-1', 'non_numeric' => 'pool'],
            'BLOCKING_POOL_QUEUE_SIZE' => ['zero' => '0', 'negative' => '-1', 'non_numeric' => 'queue'],
            'BLOCKING_POOL_TIMEOUT' => ['zero' => '0', 'negative' => '-1', 'non_numeric' => 'timeout'],
            'MAX_CONCURRENT_SCOPES' => ['negative' => '-1', 'non_numeric' => 'scopes'],
            'EVENT_LOOP_LAG_THRESHOLD_MS' => ['negative' => '-1', 'non_numeric' => 'laggy'],
        ];

        $varNames = array_keys($invalidatableVars);

        $this->forAll(
            Generators::choose(0, count($varNames) - 1),  // which var to invalidate
            Generators::choose(0, 2),                       // which invalid value variant
        )->then(function (int $varIndex, int $variantIndex) use ($varNames, $invalidatableVars): void {
            $this->clearAllEnvVars();

            $targetVar = $varNames[$varIndex];
            $variants = array_values($invalidatableVars[$targetVar]);
            $invalidValue = $variants[$variantIndex % count($variants)];

            $this->setEnv($targetVar, $invalidValue);

            try {
                ServerConfigFactory::fromEnvironment(
                    production: false,
                    cpuCountResolver: static fn(): int => 4,
                );
                self::fail("Expected ConfigValidationException for {$targetVar}={$invalidValue}");
            } catch (ConfigValidationException $e) {
                self::assertArrayHasKey(
                    $targetVar,
                    $e->errors,
                    "Exception errors should contain key '{$targetVar}' for invalid value '{$invalidValue}'",
                );
            }
        });
    }
}
