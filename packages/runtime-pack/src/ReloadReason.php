<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack;

/**
 * Reason why a worker should be reloaded.
 *
 * Used by ReloadPolicy to communicate the reload trigger to WorkerLifecycle.
 */
enum ReloadReason: string
{
    case MaxRequests = 'max_requests';
    case MaxUptime = 'max_uptime';
    case MaxMemoryRss = 'max_memory_rss';
}
