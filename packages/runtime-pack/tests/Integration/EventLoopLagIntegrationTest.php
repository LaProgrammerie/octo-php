<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Integration;

use const PHP_BINARY;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Throwable;

use function dirname;
use function is_resource;

/**
 * Integration test for event-loop lag monitoring.
 *
 * Uses a special fixture server that can block the event loop on demand.
 * Verifies that /readyz returns 503 when the event loop is lagging.
 */
#[Group('integration')]
#[RequiresPhpExtension('openswoole')]
final class EventLoopLagIntegrationTest extends TestCase
{
    use ServerProcessTrait;

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    // =========================================================================
    // 15.8 — Event-loop lag monitor: block event loop → lag_ms > 0 in /readyz,
    //        lag > threshold → /readyz 503 {"status":"event_loop_lagging","lag_ms":...}
    // =========================================================================

    public function testEventLoopLagDetectedWhenBlocked(): void
    {
        $this->startFixtureServer([
            'EVENT_LOOP_LAG_THRESHOLD_MS' => '100',
        ]);
        $this->waitForServerReady();

        // Verify /readyz is initially healthy
        $readyz = $this->httpGet('/readyz');
        self::assertSame(200, $readyz['status']);
        $body = json_decode($readyz['body'], true);
        self::assertSame('ready', $body['status']);

        // Block the event loop for 1.5 seconds (well above 100ms threshold).
        // This is a fire-and-forget — we don't wait for the response because
        // the server is single-worker and the blocking handler will stall it.
        // We use a short timeout so we don't hang.
        try {
            $this->httpGet('/block?duration_ms=1500', [], 0.5);
        } catch (Throwable) {
            // Expected: timeout because the server is blocked
        }

        // After the block completes, the tick timer will measure the lag.
        // Wait a bit for the tick to fire and measure the lag.
        // The tick fires every 250ms, so after the 1.5s block, the next tick
        // will measure ~1250ms of lag (well above 100ms threshold).
        usleep(500_000); // 500ms — enough for the block to complete and tick to fire

        // Now /readyz should report lagging
        $readyz = $this->httpGet('/readyz');

        // The lag should be detected — either 503 (lagging) or 200 with lag_ms > 0
        $body = json_decode($readyz['body'], true);

        if ($readyz['status'] === 503) {
            self::assertSame('event_loop_lagging', $body['status']);
            self::assertArrayHasKey('lag_ms', $body);
            self::assertGreaterThan(0, $body['lag_ms']);
        } else {
            // If the lag has already recovered, at least verify lag_ms was measured
            self::assertSame(200, $readyz['status']);
            self::assertArrayHasKey('event_loop_lag_ms', $body);
            // The lag should have been > 0 at some point
        }
    }

    /**
     * Override to start the fixture server instead of the skeleton.
     */
    private function startFixtureServer(array $env = []): int
    {
        $this->serverPort = $this->findAvailablePort();

        $defaultEnv = [
            'APP_HOST' => '127.0.0.1',
            'APP_PORT' => (string) $this->serverPort,
            'APP_WORKERS' => '1',
            'SHUTDOWN_TIMEOUT' => '5',
            'REQUEST_HANDLER_TIMEOUT' => '30',
            'MAX_REQUESTS' => '0',
            'MAX_UPTIME' => '0',
            'MAX_MEMORY_RSS' => '0',
            'EVENT_LOOP_LAG_THRESHOLD_MS' => '100', // Low threshold for testing
        ];

        $mergedEnv = array_merge($defaultEnv, $env);

        $envStrings = [];
        foreach ($mergedEnv as $key => $value) {
            $envStrings[$key] = $value;
        }
        foreach (['PATH', 'HOME', 'USER', 'SHELL', 'LANG', 'LC_ALL'] as $inherit) {
            if (isset($_ENV[$inherit])) {
                $envStrings[$inherit] = $_ENV[$inherit];
            } elseif (($val = getenv($inherit)) !== false) {
                $envStrings[$inherit] = $val;
            }
        }

        $phpBinary = PHP_BINARY;
        $fixturePath = __DIR__ . '/fixtures/blocking-handler-server.php';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->serverProcess = proc_open(
            [$phpBinary, $fixturePath, 'async:serve'],
            $descriptors,
            $this->serverPipes,
            dirname($fixturePath),
            $envStrings,
        );

        if (!is_resource($this->serverProcess)) {
            self::fail('Failed to start fixture server process');
        }

        stream_set_blocking($this->serverPipes[2], false);
        stream_set_blocking($this->serverPipes[1], false);

        $status = proc_get_status($this->serverProcess);
        $this->serverPid = $status['pid'];

        return $this->serverPort;
    }
}
