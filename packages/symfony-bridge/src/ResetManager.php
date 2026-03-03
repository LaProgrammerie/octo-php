<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Manages state reset between requests in a long-running process.
 *
 * Reset strategy (priority order):
 * 1. If the Kernel implements ResetInterface → $kernel->reset()
 * 2. Else if the service 'services_resetter' exists → $container->get('services_resetter')->reset()
 * 3. Else → best-effort reset + log warning
 *
 * After the main Symfony reset, all registered ResetHookInterface hooks are executed.
 * The reset is ALWAYS executed in a finally block (even if the handler threw an exception).
 *
 * Metrics: symfony_reset_duration_ms
 * Logs: debug after each reset, warning if duration > threshold
 */
final class ResetManager
{
    /** @var list<ResetHookInterface> */
    private array $hooks = [];

    public function __construct(
        private HttpKernelInterface $kernel,
        private readonly LoggerInterface $logger,
        private readonly ?MetricsBridge $metricsBridge,
        private readonly int $resetWarningMs = 50,
    ) {}

    public function addHook(ResetHookInterface $hook): void
    {
        $this->hooks[] = $hook;
    }

    /**
     * Executes the full reset cycle: kernel reset + hooks.
     *
     * Always runs in a finally-safe manner: even if resetKernel() or a hook
     * throws, the remaining hooks and metrics/logging still execute.
     */
    public function reset(string $requestId): void
    {
        $start = microtime(true);

        try {
            $this->resetKernel();
        } catch (Throwable $e) {
            $this->logger->error('Reset failed', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
        } finally {
            $this->executeHooks($requestId);
        }

        $durationMs = (microtime(true) - $start) * 1000;

        $this->metricsBridge?->recordResetDuration($durationMs);

        $this->logger->debug('Reset completed', [
            'request_id' => $requestId,
            'reset_duration_ms' => round($durationMs, 2),
        ]);

        if ($durationMs > $this->resetWarningMs) {
            $this->logger->warning('Reset duration exceeded threshold', [
                'request_id' => $requestId,
                'reset_duration_ms' => round($durationMs, 2),
                'threshold_ms' => $this->resetWarningMs,
            ]);
        }
    }

    public function getResetWarningMs(): int
    {
        return $this->resetWarningMs;
    }

    /**
     * Selects and executes the kernel reset strategy.
     *
     * Priority 1: ResetInterface on the kernel
     * Priority 2: services_resetter service in the container
     * Priority 3: best-effort (log warning)
     */
    private function resetKernel(): void
    {
        // Strategy 1: Kernel implements ResetInterface
        if ($this->kernel instanceof ResetInterface) {
            $this->kernel->reset();

            return;
        }

        // Strategy 2: services_resetter available in the container
        if (method_exists($this->kernel, 'getContainer')) {
            $container = $this->kernel->getContainer();
            if ($container !== null && $container->has('services_resetter')) {
                $container->get('services_resetter')->reset();

                return;
            }
        }

        // Strategy 3: best-effort
        $this->logger->warning('No complete reset strategy found — best-effort reset only');
    }

    /**
     * Executes all registered ResetHookInterface hooks.
     * Each hook runs in its own try/catch: a failing hook does not block the next ones.
     */
    private function executeHooks(string $requestId): void
    {
        foreach ($this->hooks as $hook) {
            try {
                $hook->reset();
            } catch (Throwable $e) {
                $this->logger->error('ResetHook failed', [
                    'request_id' => $requestId,
                    'hook' => $hook::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
