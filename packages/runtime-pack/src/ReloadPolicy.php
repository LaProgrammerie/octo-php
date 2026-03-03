<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

/**
 * Reload policy — determines whether a worker should be reloaded.
 *
 * Checks thresholds in priority order: max_requests > max_memory_rss > max_uptime.
 * A threshold set to 0 disables that specific policy.
 *
 * Memory RSS is read via /proc/self/statm (Linux only). A warning is logged
 * once if the file is not available.
 */
final class ReloadPolicy
{
    private const STATM_PATH = '/proc/self/statm';

    /**
     * Page size in bytes (4096 on Linux x86_64/arm64).
     * Used to convert /proc/self/statm RSS (in pages) to bytes.
     */
    private const PAGE_SIZE = 4096;

    /** Flag to ensure the /proc/self/statm warning is logged only once. */
    private bool $statmWarningLogged = false;

    public function __construct(
        private readonly ServerConfig $config,
        private readonly JsonLogger $logger,
    ) {
    }

    /**
     * Checks if the worker should be reloaded based on request count, uptime, and memory.
     *
     * Priority order: max_requests > max_memory_rss > max_uptime.
     * Disabled threshold (=0) is skipped.
     * If memoryRssBytes is null (throttled), the memory check is skipped.
     *
     * @param int $requestCount Number of requests handled by this worker
     * @param float $uptimeSeconds Worker uptime in seconds
     * @param int|null $memoryRssBytes Worker RSS memory in bytes (null if throttled)
     * @return ReloadReason|null Reason for reload, or null if no reload needed
     */
    public function shouldReload(int $requestCount, float $uptimeSeconds, ?int $memoryRssBytes): ?ReloadReason
    {
        // Priority 1: max_requests
        if ($this->config->maxRequests > 0 && $requestCount >= $this->config->maxRequests) {
            return ReloadReason::MaxRequests;
        }

        // Priority 2: max_memory_rss (skipped if memoryRssBytes is null / throttled)
        if (
            $this->config->maxMemoryRss > 0
            && $memoryRssBytes !== null
            && $memoryRssBytes >= $this->config->maxMemoryRss
        ) {
            return ReloadReason::MaxMemoryRss;
        }

        // Priority 3: max_uptime
        if ($this->config->maxUptime > 0 && $uptimeSeconds >= $this->config->maxUptime) {
            return ReloadReason::MaxUptime;
        }

        return null;
    }

    /**
     * Reads the current process RSS memory via /proc/self/statm.
     *
     * /proc/self/statm format: "size resident shared text lib data dt"
     * Field index 1 (resident) is RSS in pages. Multiply by PAGE_SIZE for bytes.
     *
     * Returns null if /proc/self/statm is not available (non-Linux).
     * Logs a warning once on first failure.
     *
     * @return int|null RSS in bytes, or null if not available
     */
    public function readMemoryRss(): ?int
    {
        if (!@\is_readable(self::STATM_PATH)) {
            $this->logStatmWarningOnce();
            return null;
        }

        $content = @\file_get_contents(self::STATM_PATH);

        if ($content === false) {
            $this->logStatmWarningOnce();
            return null;
        }

        $fields = \explode(' ', \trim($content));

        if (!isset($fields[1]) || !is_numeric($fields[1])) {
            $this->logStatmWarningOnce();
            return null;
        }

        return (int) $fields[1] * self::PAGE_SIZE;
    }

    /**
     * Logs a warning about /proc/self/statm not being available, but only once.
     */
    private function logStatmWarningOnce(): void
    {
        if (!$this->statmWarningLogged) {
            $this->statmWarningLogged = true;
            $this->logger->warning('/proc/self/statm not available — MAX_MEMORY_RSS policy disabled on this platform', [
                'component' => 'runtime',
            ]);
        }
    }
}
