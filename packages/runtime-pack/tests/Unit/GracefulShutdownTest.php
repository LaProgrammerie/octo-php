<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack\Tests\Unit;

use AsyncPlatform\RuntimePack\GracefulShutdown;
use AsyncPlatform\RuntimePack\JsonLogger;
use AsyncPlatform\RuntimePack\MetricsCollector;
use AsyncPlatform\RuntimePack\ReloadPolicy;
use AsyncPlatform\RuntimePack\ServerConfig;
use AsyncPlatform\RuntimePack\SignalAdapter;
use AsyncPlatform\RuntimePack\WorkerLifecycle;
use PHPUnit\Framework\TestCase;

/**
 * Fake SignalAdapter that captures signal/timer registrations for testing.
 */
final class FakeSignalAdapter implements SignalAdapter
{
    /** @var array<int, callable> signal => handler */
    public array $signals = [];

    /** @var array<int, array{ms: int, callback: callable}> timerId => timer info */
    public array $timers = [];

    /** @var int[] Cleared timer IDs */
    public array $clearedTimers = [];

    private int $nextTimerId = 1;

    public function installSignal(int $signal, callable $handler): void
    {
        $this->signals[$signal] = $handler;
    }

    public function scheduleTimer(int $ms, callable $callback): int
    {
        $id = $this->nextTimerId++;
        $this->timers[$id] = ['ms' => $ms, 'callback' => $callback];
        return $id;
    }

    public function clearTimer(int $timerId): void
    {
        $this->clearedTimers[] = $timerId;
    }

    /** Simulate firing a signal handler. */
    public function fireSignal(int $signal): void
    {
        if (isset($this->signals[$signal])) {
            ($this->signals[$signal])();
        }
    }

    /** Simulate firing a timer callback. */
    public function fireTimer(int $timerId): void
    {
        if (isset($this->timers[$timerId])) {
            ($this->timers[$timerId]['callback'])();
        }
    }
}

/**
 * Fake server object mimicking OpenSwoole\Http\Server for unit testing.
 */
final class FakeServer
{
    public int $shutdownCount = 0;

    public function shutdown(): void
    {
        $this->shutdownCount++;
    }
}

final class GracefulShutdownTest extends TestCase
{
    /** @var resource */
    private $logStream;
    private JsonLogger $logger;
    private ServerConfig $prodConfig;
    private ServerConfig $devConfig;
    private FakeSignalAdapter $adapter;

    protected function setUp(): void
    {
        $this->logStream = fopen('php://memory', 'r+');
        $this->logger = new JsonLogger(production: false, stream: $this->logStream);
        $this->prodConfig = new ServerConfig(shutdownTimeout: 30, production: true);
        $this->devConfig = new ServerConfig(shutdownTimeout: 30, production: false);
        $this->adapter = new FakeSignalAdapter();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->logStream)) {
            fclose($this->logStream);
        }
    }

    private function createShutdown(ServerConfig $config): GracefulShutdown
    {
        return new GracefulShutdown($config, $this->logger, $this->adapter);
    }

    private function createLifecycle(ServerConfig $config): WorkerLifecycle
    {
        $metrics = new MetricsCollector();
        $reloadPolicy = new ReloadPolicy($config, $this->logger);
        return new WorkerLifecycle($config, $reloadPolicy, $metrics, $this->logger, workerId: 0);
    }

    /** @return array<int, array<string, mixed>> */
    private function getLogEntries(): array
    {
        rewind($this->logStream);
        $output = stream_get_contents($this->logStream);
        $lines = array_filter(explode("\n", $output));
        return array_map(
            fn(string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values($lines),
        );
    }

    // =========================================================================
    // 6.1: registerMaster registers signal handlers
    // =========================================================================

    public function testRegisterMasterInstallsSigTermHandler(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $shutdown->registerMaster(new FakeServer());

        $this->assertArrayHasKey(SIGTERM, $this->adapter->signals);
    }

    public function testRegisterMasterInstallsSigIntInDevMode(): void
    {
        $shutdown = $this->createShutdown($this->devConfig);
        $shutdown->registerMaster(new FakeServer());

        $this->assertArrayHasKey(SIGINT, $this->adapter->signals);
    }

    public function testRegisterMasterDoesNotInstallSigIntInProduction(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $shutdown->registerMaster(new FakeServer());

        $this->assertArrayNotHasKey(SIGINT, $this->adapter->signals);
    }

    // =========================================================================
    // 6.1: registerWorker registers per-worker handlers
    // =========================================================================

    public function testRegisterWorkerInstallsSigTermHandler(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $lifecycle = $this->createLifecycle($this->prodConfig);
        $shutdown->registerWorker(new FakeServer(), $lifecycle);

        $this->assertArrayHasKey(SIGTERM, $this->adapter->signals);
    }

    public function testRegisterWorkerInstallsSigIntInDevMode(): void
    {
        $shutdown = $this->createShutdown($this->devConfig);
        $lifecycle = $this->createLifecycle($this->devConfig);
        $shutdown->registerWorker(new FakeServer(), $lifecycle);

        $this->assertArrayHasKey(SIGINT, $this->adapter->signals);
    }

    public function testRegisterWorkerDoesNotInstallSigIntInProduction(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $lifecycle = $this->createLifecycle($this->prodConfig);
        $shutdown->registerWorker(new FakeServer(), $lifecycle);

        $this->assertArrayNotHasKey(SIGINT, $this->adapter->signals);
    }

    // =========================================================================
    // 6.2: SIGTERM sets shuttingDown on worker + logs
    // =========================================================================

    public function testWorkerSigTermSetsShuttingDown(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $lifecycle = $this->createLifecycle($this->prodConfig);
        $shutdown->registerWorker(new FakeServer(), $lifecycle);

        $this->assertFalse($lifecycle->isShuttingDown());
        $this->adapter->fireSignal(SIGTERM);
        $this->assertTrue($lifecycle->isShuttingDown());
    }

    public function testWorkerSigTermLogsShutdownStartedWithInflightAndTimeout(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $lifecycle = $this->createLifecycle($this->prodConfig);
        $shutdown->registerWorker(new FakeServer(), $lifecycle);

        $this->adapter->fireSignal(SIGTERM);

        $entries = $this->getLogEntries();
        $shutdownLogs = array_values(array_filter(
            $entries,
            fn(array $e) => str_contains($e['message'], 'Graceful shutdown started (worker)'),
        ));

        $this->assertCount(1, $shutdownLogs);
        $log = $shutdownLogs[0];
        $this->assertSame('info', $log['level']);
        $this->assertSame(0, $log['extra']['worker_inflight']);
        $this->assertSame(30, $log['extra']['timeout']);
        $this->assertSame(0, $log['extra']['worker_id']);
    }

    public function testWorkerSigTermLogsCorrectInflightCount(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $lifecycle = $this->createLifecycle($this->prodConfig);

        $lifecycle->beginRequest();
        $lifecycle->beginRequest();

        $shutdown->registerWorker(new FakeServer(), $lifecycle);
        $this->adapter->fireSignal(SIGTERM);

        $entries = $this->getLogEntries();
        $log = array_values(array_filter(
            $entries,
            fn(array $e) => str_contains($e['message'], 'Graceful shutdown started (worker)'),
        ))[0];

        $this->assertSame(2, $log['extra']['worker_inflight']);
    }

    // =========================================================================
    // 6.3 & 6.4: 503 refusal and /readyz during shutdown
    // These are handled by HealthController/RequestHandler, not GracefulShutdown.
    // GracefulShutdown just manages the shutdown state via WorkerLifecycle.
    // =========================================================================

    public function testShutdownStateEnables503Refusal(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $lifecycle = $this->createLifecycle($this->prodConfig);
        $shutdown->registerWorker(new FakeServer(), $lifecycle);

        $this->adapter->fireSignal(SIGTERM);

        // After SIGTERM, lifecycle.isShuttingDown() is true
        // RequestHandler checks this for 503 refusal
        $this->assertTrue($lifecycle->isShuttingDown());
    }

    // =========================================================================
    // 6.5: Hard timeout via Timer::after
    // =========================================================================

    public function testMasterSigTermStartsHardTimer(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $shutdown->registerMaster(new FakeServer());

        $this->adapter->fireSignal(SIGTERM);

        $this->assertCount(1, $this->adapter->timers);
        $timer = array_values($this->adapter->timers)[0];
        $this->assertSame(30_000, $timer['ms']);
    }

    public function testHardTimerUsesConfiguredTimeout(): void
    {
        $config = new ServerConfig(shutdownTimeout: 15, production: true);
        $adapter = new FakeSignalAdapter();
        $shutdown = new GracefulShutdown($config, $this->logger, $adapter);
        $shutdown->registerMaster(new FakeServer());

        $adapter->fireSignal(SIGTERM);

        $timer = array_values($adapter->timers)[0];
        $this->assertSame(15_000, $timer['ms']);
    }

    public function testHardTimerForcesServerShutdown(): void
    {
        $server = new FakeServer();
        $shutdown = $this->createShutdown($this->prodConfig);
        $shutdown->registerMaster($server);

        $this->adapter->fireSignal(SIGTERM);

        $timerId = array_key_first($this->adapter->timers);
        $this->adapter->fireTimer($timerId);

        $this->assertSame(1, $server->shutdownCount);
        $this->assertTrue($shutdown->isForcedShutdown());
    }

    public function testHardTimerLogsForced(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $shutdown->registerMaster(new FakeServer());

        $this->adapter->fireSignal(SIGTERM);
        $timerId = array_key_first($this->adapter->timers);
        $this->adapter->fireTimer($timerId);

        $entries = $this->getLogEntries();
        $forcedLogs = array_filter(
            $entries,
            fn(array $e) => str_contains($e['message'], 'timeout reached'),
        );
        $this->assertNotEmpty($forcedLogs);
        $this->assertSame('warning', array_values($forcedLogs)[0]['level']);
    }

    // =========================================================================
    // 6.6: Shutdown completion log
    // =========================================================================

    public function testLogShutdownCompleteClean(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $shutdown->logShutdownComplete(true);

        $entries = $this->getLogEntries();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('clean', $entries[0]['message']);
        $this->assertSame('info', $entries[0]['level']);
    }

    public function testLogShutdownCompleteForced(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $shutdown->logShutdownComplete(false);

        $entries = $this->getLogEntries();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('forced', $entries[0]['message']);
        $this->assertSame('warning', $entries[0]['level']);
    }

    // =========================================================================
    // 6.7: Double SIGTERM → forced immediate stop
    // =========================================================================

    public function testDoubleSigTermForcesImmediateStop(): void
    {
        $server = new FakeServer();
        $shutdown = $this->createShutdown($this->prodConfig);
        $shutdown->registerMaster($server);

        $this->adapter->fireSignal(SIGTERM);
        $this->assertTrue($shutdown->isSigTermReceived());
        $this->assertSame(0, $server->shutdownCount);

        $this->adapter->fireSignal(SIGTERM);
        $this->assertSame(1, $server->shutdownCount);
        $this->assertTrue($shutdown->isForcedShutdown());
    }

    public function testDoubleSigTermClearsHardTimer(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $shutdown->registerMaster(new FakeServer());

        $this->adapter->fireSignal(SIGTERM);
        $timerId = array_key_first($this->adapter->timers);

        $this->adapter->fireSignal(SIGTERM);
        $this->assertContains($timerId, $this->adapter->clearedTimers);
    }

    public function testDoubleSigTermLogsWarning(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $shutdown->registerMaster(new FakeServer());

        $this->adapter->fireSignal(SIGTERM);
        $this->adapter->fireSignal(SIGTERM);

        $entries = $this->getLogEntries();
        $doubleLogs = array_filter(
            $entries,
            fn(array $e) => str_contains($e['message'], 'Double SIGTERM'),
        );
        $this->assertNotEmpty($doubleLogs);
        $this->assertSame('warning', array_values($doubleLogs)[0]['level']);
    }

    // =========================================================================
    // 6.8: SIGINT in dev → immediate clean stop
    // =========================================================================

    public function testSigIntInDevTriggersImmediateShutdown(): void
    {
        $server = new FakeServer();
        $shutdown = $this->createShutdown($this->devConfig);
        $shutdown->registerMaster($server);

        $this->adapter->fireSignal(SIGINT);

        $this->assertSame(1, $server->shutdownCount);
    }

    public function testSigIntInDevLogsImmediateStop(): void
    {
        $shutdown = $this->createShutdown($this->devConfig);
        $shutdown->registerMaster(new FakeServer());

        $this->adapter->fireSignal(SIGINT);

        $entries = $this->getLogEntries();
        $sigintLogs = array_filter(
            $entries,
            fn(array $e) => str_contains($e['message'], 'SIGINT'),
        );
        $this->assertNotEmpty($sigintLogs);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testWorkerDuplicateSigTermIsIgnored(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $lifecycle = $this->createLifecycle($this->prodConfig);
        $shutdown->registerWorker(new FakeServer(), $lifecycle);

        $this->adapter->fireSignal(SIGTERM);
        $this->adapter->fireSignal(SIGTERM);

        $entries = $this->getLogEntries();
        $shutdownLogs = array_filter(
            $entries,
            fn(array $e) => str_contains($e['message'], 'Graceful shutdown started (worker)'),
        );
        $this->assertCount(1, $shutdownLogs);
    }

    public function testMasterSigTermSetsReceivedFlag(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $this->assertFalse($shutdown->isSigTermReceived());

        $shutdown->registerMaster(new FakeServer());
        $this->adapter->fireSignal(SIGTERM);

        $this->assertTrue($shutdown->isSigTermReceived());
    }

    public function testForcedShutdownIsFalseByDefault(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $this->assertFalse($shutdown->isForcedShutdown());
    }

    public function testMasterSigTermLogsShutdownStartedWithTimeout(): void
    {
        $shutdown = $this->createShutdown($this->prodConfig);
        $shutdown->registerMaster(new FakeServer());

        $this->adapter->fireSignal(SIGTERM);

        $entries = $this->getLogEntries();
        $startLogs = array_values(array_filter(
            $entries,
            fn(array $e) => str_contains($e['message'], 'Graceful shutdown started (master)'),
        ));

        $this->assertCount(1, $startLogs);
        $this->assertSame('info', $startLogs[0]['level']);
        $this->assertSame(30, $startLogs[0]['extra']['timeout']);
    }

    public function testFirstSigTermDoesNotShutdownServerImmediately(): void
    {
        $server = new FakeServer();
        $shutdown = $this->createShutdown($this->prodConfig);
        $shutdown->registerMaster($server);

        $this->adapter->fireSignal(SIGTERM);

        // Critical: first SIGTERM must NOT call $server->shutdown() immediately
        // Only the hard timer or double SIGTERM should trigger it
        $this->assertSame(0, $server->shutdownCount);
    }
}
