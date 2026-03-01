<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack;

/**
 * Main entry point for the OpenSwoole HTTP server.
 *
 * Wires together all runtime-pack components:
 * - Config loading and validation (ServerConfigFactory)
 * - Startup checks (coroutine hooks, Xdebug, curl)
 * - OpenSwoole server creation with settings mapping
 * - Per-worker component instantiation (onWorkerStart)
 * - Request dispatch (onRequest → RequestHandler)
 * - Graceful shutdown registration
 * - Startup logging
 *
 * This class is the "glue" — all individual components are unit tested separately.
 * ServerBootstrap itself is validated via integration tests (Task 15).
 *
 * Boot sequence:
 * 1. Load + validate config (ServerConfigFactory::fromEnvironment())
 * 2. Startup checks (hooks, Xdebug, curl) — BEFORE any I/O
 * 3. Create OpenSwoole Server with settings
 * 4. Register onWorkerStart (per-worker instances)
 * 5. Register onRequest (delegate to RequestHandler)
 * 6. Register GracefulShutdown
 * 7. Log startup info
 * 8. Start server
 */
final class ServerBootstrap
{
    /**
     * Starts the OpenSwoole HTTP server.
     *
     * @param callable $appHandler User application handler: fn(object $request, object $response): void
     * @param bool $production Production mode flag (true = async:run, false = async:serve)
     * @param callable|null $jobRegistrar Optional job registrar for BlockingPool: fn(object $registry): void
     */
    public static function run(
        callable $appHandler,
        bool $production = false,
        ?callable $jobRegistrar = null,
    ): void {
        // === 1. LOAD + VALIDATE CONFIG ===
        $result = ServerConfigFactory::fromEnvironment($production);
        $config = $result['config'];
        $warnings = $result['warnings'];

        $logger = new JsonLogger($production);
        $runtimeLogger = $logger->withComponent('runtime');

        // Log config warnings (e.g., all reload policies disabled in prod)
        foreach ($warnings as $warning) {
            $runtimeLogger->warning($warning);
        }

        // === 2. STARTUP CHECKS ===
        self::runStartupChecks($config, $runtimeLogger, $production);

        // Capture hook flags after enableCoroutine for onWorkerStart
        $hookFlags = \OpenSwoole\Runtime::getHookFlags();

        // === 3. CREATE SERVER + SETTINGS ===
        $server = new \OpenSwoole\Http\Server($config->host, $config->port);
        $server->set(self::buildSettings($config));

        // === 4. GRACEFUL SHUTDOWN ===
        $gracefulShutdown = new GracefulShutdown($config, $logger);

        $server->on('Start', function () use ($server, $gracefulShutdown): void {
            $gracefulShutdown->registerMaster($server);
        });

        $server->on('Shutdown', function () use ($gracefulShutdown): void {
            $gracefulShutdown->logShutdownComplete(!$gracefulShutdown->isForcedShutdown());
        });

        // === 5. ON WORKER START ===
        $server->on('WorkerStart', function (\OpenSwoole\Http\Server $srv, int $workerId, ) use ($config, $production, $appHandler, $gracefulShutdown, $hookFlags, $jobRegistrar, ): void {
            self::onWorkerStart(
                $srv,
                $workerId,
                $config,
                $production,
                $appHandler,
                $gracefulShutdown,
                $hookFlags,
                $jobRegistrar,
            );
        });

        // === 6. LOG STARTUP ===
        $runtimeLogger->info('Server starting', [
            'host' => $config->host,
            'port' => $config->port,
            'workers' => $config->workers,
            'mode' => $production ? 'production' : 'development',
        ]);

        // === 7. START ===
        $server->start();
    }

    /**
     * Runs startup checks before server creation.
     *
     * Sequence:
     * 1. Enable coroutine hooks (SWOOLE_HOOK_ALL) — BEFORE any I/O
     * 2. Verify hook flags, log result
     * 3. Log SWOOLE_HOOK_CURL status explicitly
     * 4. In prod: fail-fast if required flags missing
     * 5. In prod: fail-fast if Xdebug loaded
     * 6. Log hook dependencies
     */
    private static function runStartupChecks(
        ServerConfig $config,
        JsonLogger $logger,
        bool $production,
    ): void {
        // 1. Enable coroutine hooks BEFORE any I/O
        \OpenSwoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        // 2. Verify hook flags
        $hookFlags = \OpenSwoole\Runtime::getHookFlags();
        $logger->info('Coroutine hooks enabled', [
            'flags' => $hookFlags,
            'SWOOLE_HOOK_ALL' => SWOOLE_HOOK_ALL,
            'match' => ($hookFlags & SWOOLE_HOOK_ALL) === SWOOLE_HOOK_ALL,
        ]);

        // 3. Log SWOOLE_HOOK_CURL status
        $curlHookActive = ($hookFlags & SWOOLE_HOOK_CURL) === SWOOLE_HOOK_CURL;
        $logger->info('SWOOLE_HOOK_CURL active: ' . ($curlHookActive ? 'yes' : 'no'), [
            'curl_extension_loaded' => \extension_loaded('curl'),
            'hook_curl_flag' => $curlHookActive,
        ]);

        // 4. In prod: fail-fast if required flags missing
        if ($production) {
            $requiredFlags = SWOOLE_HOOK_ALL;
            if (($hookFlags & $requiredFlags) !== $requiredFlags) {
                $logger->critical('Required coroutine hook flags missing in production', [
                    'expected' => $requiredFlags,
                    'actual' => $hookFlags,
                    'missing' => $requiredFlags & ~$hookFlags,
                ]);
                exit(1);
            }
        }

        // 5. In prod: Xdebug MUST be OFF
        if ($production && \extension_loaded('xdebug')) {
            $logger->critical(
                'Xdebug is loaded in production mode — incompatible with coroutine scheduling',
                [
                    'action' => 'Remove Xdebug from production PHP configuration',
                    'hint' => 'Use the prod Docker stage which does not include Xdebug',
                ],
            );
            exit(1);
        }

        // 6. Log hook dependencies
        $hookDeps = [];
        if (\extension_loaded('curl') && $curlHookActive) {
            $hookDeps['curl'] = 'available (SWOOLE_HOOK_CURL active: yes)';
        } elseif (\extension_loaded('curl') && !$curlHookActive) {
            $hookDeps['curl'] = 'ext-curl loaded but SWOOLE_HOOK_CURL inactive — curl calls may block';
            if ($production) {
                $logger->warning('ext-curl loaded but SWOOLE_HOOK_CURL inactive', [
                    'impact' => 'curl calls may block the event loop. Use stream wrappers or rebuild OpenSwoole with curl support.',
                ]);
            }
        } else {
            $hookDeps['curl'] = 'not available (ext-curl not loaded, SWOOLE_HOOK_CURL inactive — use stream wrappers instead)';
            if ($production) {
                $logger->warning('ext-curl not loaded — SWOOLE_HOOK_CURL inactive', [
                    'impact' => 'Guzzle/curl will use stream wrappers (still async via SWOOLE_HOOK_FILE)',
                ]);
            }
        }
        $logger->info('Hook dependencies check', ['deps' => $hookDeps]);
    }

    /**
     * Builds the OpenSwoole server settings array from ServerConfig.
     *
     * @return array<string, mixed>
     */
    private static function buildSettings(ServerConfig $config): array
    {
        return [
            // Worker count
            'worker_num' => $config->workers,

            // Request body size limit
            'package_max_length' => $config->maxRequestBodySize,

            // Max simultaneous connections
            'max_connection' => $config->maxConnections,

            // Max requests per worker before native reload (OpenSwoole native)
            'max_request' => $config->maxRequests,

            // Always enable HTTP protocol parsing
            'open_http_protocol' => true,

            // Always enable HTTP compression (gzip/br/deflate based on Accept-Encoding)
            'http_compression' => true,

            // Security: strip server version header
            // OpenSwoole adds "Server: OpenSwoole x.x.x" by default.
            // Setting to empty string suppresses it.
            'http_server_software' => '',
        ];
    }

    /**
     * Per-worker initialization callback.
     *
     * Creates all per-worker component instances and registers event handlers.
     * Each worker gets its own isolated set of components (no shared mutable state).
     */
    private static function onWorkerStart(
        \OpenSwoole\Http\Server $server,
        int $workerId,
        ServerConfig $config,
        bool $production,
        callable $appHandler,
        GracefulShutdown $gracefulShutdown,
        int $hookFlags,
        ?callable $jobRegistrar,
    ): void {
        // --- Per-worker component instantiation ---
        $logger = new JsonLogger($production);
        $metrics = new MetricsCollector();
        $metrics->setWorkersConfigured($config->workers);

        $reloadPolicy = new ReloadPolicy($config, $logger);
        $lifecycle = new WorkerLifecycle($config, $reloadPolicy, $metrics, $logger, $workerId);
        $healthController = new HealthController($lifecycle);
        $requestIdMiddleware = new RequestIdMiddleware($logger);

        // --- Register worker-side signal handlers (SIGTERM/SIGINT) ---
        $gracefulShutdown->registerWorker($server, $lifecycle);

        // --- Start tick timer (250ms) for event loop health monitoring ---
        \OpenSwoole\Timer::tick(WorkerLifecycle::TICK_INTERVAL_MS, function () use ($lifecycle): void {
            $lifecycle->tick();
        });

        // --- TODO: BlockingPool::initWorker() (Task 17) ---
        // Initialize BlockingPool for this worker
        $jobRegistry = new JobRegistry();
        if ($jobRegistrar !== null) {
            $jobRegistrar($jobRegistry);
        }

        $blockingPool = new BlockingPool(
            registry: $jobRegistry,
            maxWorkers: $config->blockingPoolWorkers,
            maxQueueSize: $config->blockingPoolQueueSize,
            defaultTimeoutSeconds: (float) $config->blockingPoolTimeout,
            metrics: $metrics,
            logger: $logger->withComponent('blocking_pool'),
        );

        // Initialize outbound channel + dispatcher coroutine
        if ($config->blockingPoolWorkers > 0) {
            $blockingPool->initWorker();

            // Start periodic cleanup timer for orphaned jobs (60s)
            \OpenSwoole\Timer::tick(
                BlockingPool::CLEANUP_INTERVAL_SECONDS * 1000,
                function () use ($blockingPool): void {
                    $blockingPool->cleanupOrphanedJobs();
                },
            );
        }

        // --- ExecutionPolicy: centralized async safety matrix ---
        $executionPolicy = ExecutionPolicy::defaults($hookFlags);

        // Log the resolved policy for observability
        $logger->withComponent('runtime')->debug('ExecutionPolicy initialized', [
            'worker_id' => $workerId,
            'hook_flags' => $hookFlags,
            'policies' => \array_map(
                static fn(ExecutionStrategy $s): string => $s->value,
                $executionPolicy->all(),
            ),
        ]);

        // --- Register onRequest handler via RequestHandler ---
        $requestHandler = new RequestHandler(
            health: $healthController,
            requestId: $requestIdMiddleware,
            logger: $logger,
            metrics: $metrics,
            config: $config,
            lifecycle: $lifecycle,
            appHandler: $appHandler,
        );

        $server->on('Request', function (\OpenSwoole\Http\Request $request, \OpenSwoole\Http\Response $response, ) use ($requestHandler): void {
            $requestHandler->handle($request, $response);
        });
    }

    /**
     * Handles an incoming HTTP request.
     *
     * Minimal dispatch logic (full routing in RequestHandler, Task 10):
     * 1. Resolve request ID (extract or generate)
     * 2. Set X-Request-Id response header
     * 3. Strip version headers (Server, X-Powered-By) for security
     * 4. If shutting down and not a health endpoint → 503
     * 5. Route: /healthz → HealthController, /readyz → HealthController, else → app handler
     * 6. Log access, increment metrics, check reload policy
     *
     * Note: This will be replaced by RequestHandler::handle() in Task 10.
     * The current implementation covers the essential wiring for Task 9.
     */
}
