<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack;

/**
 * Immutable server configuration DTO, validated at boot time.
 *
 * All values are resolved and validated by ServerConfigFactory::fromEnvironment().
 * This class is a pure data holder — no logic, no side effects.
 */
final readonly class ServerConfig
{
    public function __construct(
        /** Server bind address. */
        public string $host = '0.0.0.0',

        /** Server bind port (1-65535). */
        public int $port = 8080,

        /** Number of worker processes. 0 = auto-detect (resolved by factory). */
        public int $workers = 0,

        /** Production mode flag. */
        public bool $production = false,

        /** Max HTTP request body size in bytes (> 0). Maps to OpenSwoole package_max_length. */
        public int $maxRequestBodySize = 2_097_152, // 2 MB

        /** Max simultaneous connections (> 0). Maps to OpenSwoole max_connection. */
        public int $maxConnections = 1024,

        /** Request handler timeout in seconds (> 0). Applicative deadline for ScopeRunner. */
        public int $requestHandlerTimeout = 60,

        /** Graceful shutdown timeout in seconds (> 0). */
        public int $shutdownTimeout = 30,

        /** Max requests per worker before reload. 0 = disabled. Maps to OpenSwoole max_request. */
        public int $maxRequests = 10_000,

        /** Max worker uptime in seconds before reload. 0 = disabled. */
        public int $maxUptime = 3_600,

        /** Max worker RSS memory in bytes before reload. 0 = disabled. */
        public int $maxMemoryRss = 134_217_728, // 128 MB

        /** Min interval between worker restarts in seconds. Anti crash-loop guard. */
        public int $workerRestartMinInterval = 5,

        // --- Async V1 ---

        /** Number of blocking pool worker processes. 0 = disabled. */
        public int $blockingPoolWorkers = 4,

        /** Blocking pool outbound queue capacity (>= 1). */
        public int $blockingPoolQueueSize = 64,

        /** Blocking pool job timeout in seconds (>= 1). */
        public int $blockingPoolTimeout = 30,

        /** Max concurrent scopes per worker. 0 = unlimited, > 0 = semaphore. */
        public int $maxConcurrentScopes = 0,

        // --- Event-loop lag monitor (V1) ---

        /** Event loop lag threshold in milliseconds. 0 = disabled, > 0 = /readyz 503 threshold. */
        public float $eventLoopLagThresholdMs = 500.0,
    ) {
    }
}
