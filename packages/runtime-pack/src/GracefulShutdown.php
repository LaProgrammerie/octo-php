<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

/**
 * Graceful shutdown manager for the OpenSwoole HTTP server.
 *
 * Handles SIGTERM/SIGINT signals with the following behavior:
 *
 * SIGTERM (graceful):
 *   - Worker-side: startShutdown() on WorkerLifecycle → cancels active scopes
 *   - Master-side: hard timer ($server->shutdown() after shutdownTimeout) as safety net
 *   - Drain waits for all active scopes to complete
 *   - Double SIGTERM → forced immediate stop
 *
 * SIGINT (dev only): immediate clean stop.
 *
 * Stop order:
 * 1) Workers drain (cancel scopes, wait inflight)
 * 2) BlockingPool stop (wait pending jobs, timeout)
 * 3) Master exit
 *
 * Critical rule: The master-side SIGTERM handler triggers ONLY a hard timer,
 * never an immediate $server->shutdown(). An immediate shutdown would bypass
 * scope drain in workers.
 *
 * This class is instantiated once at boot. registerMaster() is called once,
 * registerWorker() is called per-worker in onWorkerStart.
 *
 * Testability: OpenSwoole primitives (Process::signal, Timer::after, Timer::clear)
 * are injected via a SignalAdapter interface, allowing unit tests without the extension.
 */
final class GracefulShutdown
{
    /** Master-side flag for double SIGTERM detection. */
    private bool $sigTermReceived = false;

    /** Master-side hard timer ID (for cleanup if needed). */
    private ?int $hardTimerId = null;

    /** Whether forced shutdown was triggered (double SIGTERM or hard timeout). */
    private bool $forcedShutdown = false;

    /** Logger scoped with component='runtime'. */
    private readonly JsonLogger $runtimeLogger;

    /**
     * @param ServerConfig $config Server configuration
     * @param JsonLogger $logger Logger instance
     * @param SignalAdapter|null $signalAdapter Adapter for signal/timer operations (null = OpenSwoole defaults)
     */
    public function __construct(
        private readonly ServerConfig $config,
        JsonLogger $logger,
        private readonly ?SignalAdapter $signalAdapter = null,
    ) {
        $this->runtimeLogger = $logger->withComponent('runtime');
    }

    /**
     * Register master-side signal handlers. Called once at boot (onStart callback).
     *
     * Master-side SIGTERM:
     * - First SIGTERM: log shutdown started, start hard timer
     * - Second SIGTERM: forced immediate stop ($server->shutdown())
     *
     * Master-side SIGINT (dev only): immediate clean stop.
     *
     * @param object $server OpenSwoole HTTP Server (typed as object for testability)
     */
    public function registerMaster(object $server): void
    {
        $shutdownTimeout = $this->config->shutdownTimeout;
        $production = $this->config->production;

        // SIGTERM handler (master-side safety net)
        $this->installSignal(SIGTERM, function () use ($server, $shutdownTimeout): void {
            if ($this->sigTermReceived) {
                // Double SIGTERM → forced immediate stop
                $this->forcedShutdown = true;
                $this->runtimeLogger->warning('Double SIGTERM received, forcing immediate shutdown');

                if ($this->hardTimerId !== null) {
                    $this->clearTimer($this->hardTimerId);
                    $this->hardTimerId = null;
                }

                $server->shutdown();
                return;
            }

            $this->sigTermReceived = true;

            $this->runtimeLogger->info('Graceful shutdown started (master)', [
                'timeout' => $shutdownTimeout,
            ]);

            // Hard timer: safety net — force shutdown after timeout
            $this->hardTimerId = $this->scheduleTimer(
                $shutdownTimeout * 1000,
                function () use ($server): void {
                    $this->forcedShutdown = true;
                    $this->runtimeLogger->warning('Shutdown timeout reached, forcing server stop');
                    $server->shutdown();
                },
            );
        });

        // SIGINT handler (dev: immediate clean stop)
        if (!$production) {
            $this->installSignal(SIGINT, function () use ($server): void {
                $this->runtimeLogger->info('SIGINT received (dev mode), immediate clean stop');
                $server->shutdown();
            });
        }
    }

    /**
     * Register worker-side signal handlers. Called per-worker in onWorkerStart.
     *
     * Worker-side SIGTERM:
     * - Calls lifecycle->startShutdown() to set shuttingDown flag
     * - Logs shutdown started with inflight count and timeout
     * - New requests will be refused with 503 (handled by RequestHandler)
     * - Active scopes will drain naturally
     *
     * Worker-side SIGINT (dev only): immediate clean stop via exit(0).
     *
     * @param object $server OpenSwoole HTTP Server (typed as object for testability)
     * @param WorkerLifecycle $lifecycle Per-worker lifecycle instance
     */
    public function registerWorker(object $server, WorkerLifecycle $lifecycle): void
    {
        $shutdownTimeout = $this->config->shutdownTimeout;
        $production = $this->config->production;

        // Worker-side SIGTERM handler
        $this->installSignal(SIGTERM, function () use ($lifecycle, $shutdownTimeout): void {
            if ($lifecycle->isShuttingDown()) {
                // Already shutting down — ignore duplicate
                return;
            }

            $lifecycle->startShutdown();

            $this->runtimeLogger->info('Graceful shutdown started (worker)', [
                'worker_id' => $lifecycle->getWorkerId(),
                'worker_inflight' => $lifecycle->getInflightScopes(),
                'timeout' => $shutdownTimeout,
            ]);
        });

        // Worker-side SIGINT (dev only): immediate exit
        if (!$production) {
            $this->installSignal(SIGINT, function () use ($lifecycle): void {
                $this->runtimeLogger->info('SIGINT received (dev mode), worker immediate stop', [
                    'worker_id' => $lifecycle->getWorkerId(),
                ]);
                exit(0);
            });
        }
    }

    /**
     * Log the shutdown completion status.
     *
     * Called after the server has stopped to indicate whether shutdown was clean
     * (all requests completed) or forced (timeout reached / double SIGTERM).
     */
    public function logShutdownComplete(bool $clean): void
    {
        if ($clean) {
            $this->runtimeLogger->info('Shutdown complete: clean (all requests finished)');
        } else {
            $this->runtimeLogger->warning('Shutdown complete: forced (timeout or double SIGTERM)');
        }
    }

    /** Whether the master has received at least one SIGTERM. */
    public function isSigTermReceived(): bool
    {
        return $this->sigTermReceived;
    }

    /** Whether shutdown was forced (double SIGTERM or hard timeout). */
    public function isForcedShutdown(): bool
    {
        return $this->forcedShutdown;
    }

    // --- Private helpers delegating to SignalAdapter or OpenSwoole ---

    private function installSignal(int $signal, callable $handler): void
    {
        if ($this->signalAdapter !== null) {
            $this->signalAdapter->installSignal($signal, $handler);
            return;
        }
        \OpenSwoole\Process::signal($signal, $handler);
    }

    private function scheduleTimer(int $ms, callable $callback): int
    {
        if ($this->signalAdapter !== null) {
            return $this->signalAdapter->scheduleTimer($ms, $callback);
        }
        return \OpenSwoole\Timer::after($ms, $callback);
    }

    private function clearTimer(int $timerId): void
    {
        if ($this->signalAdapter !== null) {
            $this->signalAdapter->clearTimer($timerId);
            return;
        }
        \OpenSwoole\Timer::clear($timerId);
    }
}
