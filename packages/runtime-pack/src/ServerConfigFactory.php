<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack;

use AsyncPlatform\RuntimePack\Exception\ConfigValidationException;

/**
 * Creates and validates ServerConfig from environment variables.
 *
 * Reads all configuration from getenv(), validates types and bounds,
 * and throws ConfigValidationException listing ALL invalid variables
 * (never throws on the first error alone).
 *
 * The factory accepts a $production flag and an optional callable for
 * resolving CPU count (for testability — defaults to swoole_cpu_num()).
 */
final class ServerConfigFactory
{
    /**
     * Environment variable name → ServerConfig field mapping with defaults and validation rules.
     *
     * Format: [envVar => [field, default, type, min, max]]
     * type: 'int', 'float', 'string'
     * min/max: null means no bound check (beyond type validation)
     */
    private const ENV_MAP = [
        'APP_HOST' => ['host', '0.0.0.0', 'string', null, null],
        'APP_PORT' => ['port', '8080', 'int', 1, 65535],
        'APP_WORKERS' => ['workers', '0', 'int', 0, null],
        'MAX_REQUEST_BODY_SIZE' => ['maxRequestBodySize', '2097152', 'int', 1, null],
        'MAX_CONNECTIONS' => ['maxConnections', '1024', 'int', 1, null],
        'REQUEST_HANDLER_TIMEOUT' => ['requestHandlerTimeout', '60', 'int', 1, null],
        'SHUTDOWN_TIMEOUT' => ['shutdownTimeout', '30', 'int', 1, null],
        'MAX_REQUESTS' => ['maxRequests', '10000', 'int', 0, null],
        'MAX_UPTIME' => ['maxUptime', '3600', 'int', 0, null],
        'MAX_MEMORY_RSS' => ['maxMemoryRss', '134217728', 'int', 0, null],
        'WORKER_RESTART_MIN_INTERVAL' => ['workerRestartMinInterval', '5', 'int', 0, null],
        'BLOCKING_POOL_WORKERS' => ['blockingPoolWorkers', '4', 'int', 0, null],
        'BLOCKING_POOL_QUEUE_SIZE' => ['blockingPoolQueueSize', '64', 'int', 1, null],
        'BLOCKING_POOL_TIMEOUT' => ['blockingPoolTimeout', '30', 'int', 1, null],
        'MAX_CONCURRENT_SCOPES' => ['maxConcurrentScopes', '0', 'int', 0, null],
        'EVENT_LOOP_LAG_THRESHOLD_MS' => ['eventLoopLagThresholdMs', '500.0', 'float', 0.0, null],
    ];

    /**
     * Creates a ServerConfig from environment variables.
     *
     * Collects ALL validation errors before throwing.
     *
     * @param bool          $production   Production mode flag.
     * @param callable|null $cpuCountResolver Returns CPU count (default: swoole_cpu_num). Signature: (): int
     *
     * @return array{config: ServerConfig, warnings: string[]}
     *
     * @throws ConfigValidationException If any environment variable is invalid.
     */
    public static function fromEnvironment(
        bool $production = false,
        ?callable $cpuCountResolver = null,
    ): array {
        /** @var array<string, string> $errors */
        $errors = [];

        /** @var array<string, mixed> $values */
        $values = [];

        foreach (self::ENV_MAP as $envVar => [$field, $default, $type, $min, $max]) {
            $raw = self::readEnv($envVar) ?? $default;

            match ($type) {
                'int' => self::validateInt($envVar, $raw, $min, $max, $errors, $values, $field),
                'float' => self::validateFloat($envVar, $raw, $min, $max, $errors, $values, $field),
                'string' => self::validateString($envVar, $raw, $errors, $values, $field),
            };
        }

        if ($errors !== []) {
            throw new ConfigValidationException($errors);
        }

        // Resolve workers after validation
        $values['workers'] = self::resolveWorkers(
            $production,
            $values['workers'],
            $cpuCountResolver,
        );

        $values['production'] = $production;

        $config = new ServerConfig(...$values);

        // Collect warnings
        $warnings = [];
        if ($production && $config->maxRequests === 0 && $config->maxUptime === 0 && $config->maxMemoryRss === 0) {
            $warnings[] = 'All reload policies are disabled in production mode (MAX_REQUESTS=0, MAX_UPTIME=0, MAX_MEMORY_RSS=0). '
                . 'Workers will never be reloaded, risking memory leaks and state drift.';
        }

        return ['config' => $config, 'warnings' => $warnings];
    }

    /**
     * Resolves the number of workers based on mode and explicit configuration.
     *
     * - If $configured > 0: use that value (explicit override).
     * - If $configured === 0 (auto): prod → CPU count, dev → 2.
     */
    private static function resolveWorkers(
        bool $production,
        int $configured,
        ?callable $cpuCountResolver,
    ): int {
        if ($configured > 0) {
            return $configured;
        }

        if ($production) {
            $resolver = $cpuCountResolver ?? static fn(): int => \swoole_cpu_num();
            return $resolver();
        }

        return 2;
    }

    /**
     * Reads an environment variable. Returns null if not set or empty string.
     */
    private static function readEnv(string $name): ?string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Validates and stores an integer environment variable.
     *
     * @param array<string, string> $errors
     * @param array<string, mixed>  $values
     */
    private static function validateInt(
        string $envVar,
        string $raw,
        ?int $min,
        ?int $max,
        array &$errors,
        array &$values,
        string $field,
    ): void {
        $filtered = filter_var($raw, FILTER_VALIDATE_INT);

        if ($filtered === false) {
            $errors[$envVar] = "must be an integer, got '{$raw}'";
            return;
        }

        $value = (int) $filtered;

        if ($min !== null && $value < $min) {
            $errors[$envVar] = $max !== null
                ? "must be between {$min} and {$max}, got {$value}"
                : "must be >= {$min}, got {$value}";
            return;
        }

        if ($max !== null && $value > $max) {
            $errors[$envVar] = "must be between {$min} and {$max}, got {$value}";
            return;
        }

        $values[$field] = $value;
    }

    /**
     * Validates and stores a float environment variable.
     *
     * @param array<string, string> $errors
     * @param array<string, mixed>  $values
     */
    private static function validateFloat(
        string $envVar,
        string $raw,
        ?float $min,
        ?float $max,
        array &$errors,
        array &$values,
        string $field,
    ): void {
        if (!is_numeric($raw)) {
            $errors[$envVar] = "must be a number, got '{$raw}'";
            return;
        }

        $value = (float) $raw;

        if ($min !== null && $value < $min) {
            $errors[$envVar] = $max !== null
                ? "must be between {$min} and {$max}, got {$value}"
                : "must be >= {$min}, got {$value}";
            return;
        }

        if ($max !== null && $value > $max) {
            $errors[$envVar] = "must be between {$min} and {$max}, got {$value}";
            return;
        }

        $values[$field] = $value;
    }

    /**
     * Validates and stores a string environment variable.
     *
     * @param array<string, string> $errors
     * @param array<string, mixed>  $values
     */
    private static function validateString(
        string $envVar,
        string $raw,
        array &$errors,
        array &$values,
        string $field,
    ): void {
        if ($raw === '') {
            $errors[$envVar] = 'must not be empty';
            return;
        }

        $values[$field] = $raw;
    }
}
