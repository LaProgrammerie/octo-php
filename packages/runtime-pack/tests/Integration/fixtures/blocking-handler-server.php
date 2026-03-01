<?php

declare(strict_types=1);

/**
 * Test fixture: server with a handler that blocks the event loop on /block.
 *
 * Used by EventLoopLagIntegrationTest to verify that event loop lag is detected
 * and /readyz returns 503 when the loop is stalled.
 */

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use AsyncPlatform\RuntimePack\ServerBootstrap;

$appHandler = static function (object $request, object $response): void {
    $path = $request->server['request_uri'] ?? '/';

    if ($path === '/block') {
        // Block the event loop with a CPU-bound busy-wait.
        // This simulates a handler that starves the event loop.
        $durationMs = (int) ($request->get['duration_ms'] ?? 2000);
        $end = microtime(true) + ($durationMs / 1000);
        while (microtime(true) < $end) {
            // busy-wait — blocks the event loop
        }
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['blocked_ms' => $durationMs]));
        return;
    }

    if ($path === '/slow') {
        // A slow handler that yields (async sleep) — does NOT block the event loop
        $durationMs = (int) ($request->get['duration_ms'] ?? 1000);
        \OpenSwoole\Coroutine::sleep($durationMs / 1000);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['slept_ms' => $durationMs]));
        return;
    }

    $response->header('Content-Type', 'application/json');
    $response->end(json_encode(['status' => 'ok']));
};

// Parse command
$command = $argv[1] ?? 'async:serve';
$production = ($command === 'async:run');

ServerBootstrap::run(
    appHandler: $appHandler,
    production: $production,
);
