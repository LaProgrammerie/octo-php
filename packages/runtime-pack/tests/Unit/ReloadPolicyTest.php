<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use Octo\RuntimePack\JsonLogger;
use Octo\RuntimePack\ReloadPolicy;
use Octo\RuntimePack\ReloadReason;
use Octo\RuntimePack\ServerConfig;
use PHPUnit\Framework\TestCase;

final class ReloadPolicyTest extends TestCase
{
    /** @var resource */
    private $logStream;
    private JsonLogger $logger;

    protected function setUp(): void
    {
        $this->logStream = fopen('php://memory', 'r+');
        $this->logger = new JsonLogger(production: false, stream: $this->logStream);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->logStream)) {
            fclose($this->logStream);
        }
    }

    private function getLogOutput(): string
    {
        rewind($this->logStream);
        return stream_get_contents($this->logStream);
    }

    private function createPolicy(
        int $maxRequests = 10_000,
        int $maxUptime = 3_600,
        int $maxMemoryRss = 134_217_728,
    ): ReloadPolicy {
        $config = new ServerConfig(
            maxRequests: $maxRequests,
            maxUptime: $maxUptime,
            maxMemoryRss: $maxMemoryRss,
        );
        return new ReloadPolicy($config, $this->logger);
    }

    // ---------------------------------------------------------------
    // shouldReload — MaxRequests (Priority 1)
    // ---------------------------------------------------------------

    public function testShouldReloadReturnsMaxRequestsWhenThresholdReached(): void
    {
        $policy = $this->createPolicy(maxRequests: 100);

        $result = $policy->shouldReload(100, 10.0, null);

        self::assertSame(ReloadReason::MaxRequests, $result);
    }

    public function testShouldReloadReturnsMaxRequestsWhenThresholdExceeded(): void
    {
        $policy = $this->createPolicy(maxRequests: 100);

        $result = $policy->shouldReload(150, 10.0, null);

        self::assertSame(ReloadReason::MaxRequests, $result);
    }

    public function testShouldReloadReturnsNullWhenBelowMaxRequests(): void
    {
        $policy = $this->createPolicy(maxRequests: 100);

        $result = $policy->shouldReload(99, 10.0, null);

        self::assertNull($result);
    }

    public function testShouldReloadSkipsMaxRequestsWhenDisabled(): void
    {
        $policy = $this->createPolicy(maxRequests: 0, maxUptime: 0, maxMemoryRss: 0);

        $result = $policy->shouldReload(999_999, 999_999.0, 999_999_999);

        self::assertNull($result);
    }

    // ---------------------------------------------------------------
    // shouldReload — MaxMemoryRss (Priority 2)
    // ---------------------------------------------------------------

    public function testShouldReloadReturnsMaxMemoryRssWhenThresholdReached(): void
    {
        $policy = $this->createPolicy(maxRequests: 0, maxMemoryRss: 128 * 1024 * 1024);

        $result = $policy->shouldReload(1, 10.0, 128 * 1024 * 1024);

        self::assertSame(ReloadReason::MaxMemoryRss, $result);
    }

    public function testShouldReloadReturnsMaxMemoryRssWhenThresholdExceeded(): void
    {
        $policy = $this->createPolicy(maxRequests: 0, maxMemoryRss: 128 * 1024 * 1024);

        $result = $policy->shouldReload(1, 10.0, 200 * 1024 * 1024);

        self::assertSame(ReloadReason::MaxMemoryRss, $result);
    }

    public function testShouldReloadSkipsMemoryCheckWhenMemoryRssBytesIsNull(): void
    {
        $policy = $this->createPolicy(maxRequests: 0, maxMemoryRss: 128 * 1024 * 1024, maxUptime: 0);

        $result = $policy->shouldReload(1, 10.0, null);

        self::assertNull($result);
    }

    public function testShouldReloadSkipsMaxMemoryRssWhenDisabled(): void
    {
        $policy = $this->createPolicy(maxRequests: 0, maxMemoryRss: 0, maxUptime: 0);

        $result = $policy->shouldReload(1, 10.0, 999_999_999);

        self::assertNull($result);
    }

    // ---------------------------------------------------------------
    // shouldReload — MaxUptime (Priority 3)
    // ---------------------------------------------------------------

    public function testShouldReloadReturnsMaxUptimeWhenThresholdReached(): void
    {
        $policy = $this->createPolicy(maxRequests: 0, maxMemoryRss: 0, maxUptime: 3600);

        $result = $policy->shouldReload(1, 3600.0, null);

        self::assertSame(ReloadReason::MaxUptime, $result);
    }

    public function testShouldReloadReturnsMaxUptimeWhenThresholdExceeded(): void
    {
        $policy = $this->createPolicy(maxRequests: 0, maxMemoryRss: 0, maxUptime: 3600);

        $result = $policy->shouldReload(1, 4000.0, null);

        self::assertSame(ReloadReason::MaxUptime, $result);
    }

    public function testShouldReloadReturnsNullWhenBelowMaxUptime(): void
    {
        $policy = $this->createPolicy(maxRequests: 0, maxMemoryRss: 0, maxUptime: 3600);

        $result = $policy->shouldReload(1, 3599.9, null);

        self::assertNull($result);
    }

    public function testShouldReloadSkipsMaxUptimeWhenDisabled(): void
    {
        $policy = $this->createPolicy(maxRequests: 0, maxMemoryRss: 0, maxUptime: 0);

        $result = $policy->shouldReload(1, 999_999.0, null);

        self::assertNull($result);
    }

    // ---------------------------------------------------------------
    // shouldReload — Priority order
    // ---------------------------------------------------------------

    public function testShouldReloadPriorityMaxRequestsWinsOverMaxMemoryRss(): void
    {
        $policy = $this->createPolicy(maxRequests: 100, maxMemoryRss: 1024);

        // Both thresholds exceeded — max_requests should win
        $result = $policy->shouldReload(100, 10.0, 2048);

        self::assertSame(ReloadReason::MaxRequests, $result);
    }

    public function testShouldReloadPriorityMaxRequestsWinsOverMaxUptime(): void
    {
        $policy = $this->createPolicy(maxRequests: 100, maxUptime: 60);

        // Both thresholds exceeded — max_requests should win
        $result = $policy->shouldReload(100, 120.0, null);

        self::assertSame(ReloadReason::MaxRequests, $result);
    }

    public function testShouldReloadPriorityMaxMemoryRssWinsOverMaxUptime(): void
    {
        $policy = $this->createPolicy(maxRequests: 0, maxMemoryRss: 1024, maxUptime: 60);

        // Both memory and uptime exceeded — memory should win
        $result = $policy->shouldReload(1, 120.0, 2048);

        self::assertSame(ReloadReason::MaxMemoryRss, $result);
    }

    public function testShouldReloadPriorityAllThreeExceeded(): void
    {
        $policy = $this->createPolicy(maxRequests: 10, maxMemoryRss: 1024, maxUptime: 60);

        // All three exceeded — max_requests should win
        $result = $policy->shouldReload(20, 120.0, 2048);

        self::assertSame(ReloadReason::MaxRequests, $result);
    }

    // ---------------------------------------------------------------
    // shouldReload — All disabled
    // ---------------------------------------------------------------

    public function testShouldReloadReturnsNullWhenAllThresholdsDisabled(): void
    {
        $policy = $this->createPolicy(maxRequests: 0, maxMemoryRss: 0, maxUptime: 0);

        $result = $policy->shouldReload(999_999, 999_999.0, 999_999_999);

        self::assertNull($result);
    }

    public function testShouldReloadReturnsNullWhenBelowAllThresholds(): void
    {
        $policy = $this->createPolicy(maxRequests: 10_000, maxMemoryRss: 134_217_728, maxUptime: 3_600);

        $result = $policy->shouldReload(1, 1.0, 1024);

        self::assertNull($result);
    }

    // ---------------------------------------------------------------
    // readMemoryRss — /proc/self/statm
    // ---------------------------------------------------------------

    public function testReadMemoryRssReturnsNullOnNonLinux(): void
    {
        // On macOS (CI or dev), /proc/self/statm does not exist
        if (is_readable('/proc/self/statm')) {
            self::markTestSkipped('/proc/self/statm is available on this platform');
        }

        $policy = $this->createPolicy();
        $result = $policy->readMemoryRss();

        self::assertNull($result);
    }

    public function testReadMemoryRssReturnsPositiveIntOnLinux(): void
    {
        if (!is_readable('/proc/self/statm')) {
            self::markTestSkipped('/proc/self/statm not available on this platform');
        }

        $policy = $this->createPolicy();
        $result = $policy->readMemoryRss();

        self::assertIsInt($result);
        self::assertGreaterThan(0, $result);
    }

    public function testReadMemoryRssLogsWarningOnceWhenNotAvailable(): void
    {
        if (is_readable('/proc/self/statm')) {
            self::markTestSkipped('/proc/self/statm is available on this platform');
        }

        $policy = $this->createPolicy();

        // Call twice — warning should be logged only once
        $policy->readMemoryRss();
        $policy->readMemoryRss();

        $logOutput = $this->getLogOutput();
        $lines = array_filter(explode("\n", trim($logOutput)));

        // Filter for the specific warning
        $warningLines = array_filter($lines, function (string $line): bool {
            $decoded = json_decode($line, true);
            return $decoded !== null
                && ($decoded['level'] ?? '') === 'warning'
                && str_contains($decoded['message'] ?? '', '/proc/self/statm');
        });

        self::assertCount(1, $warningLines, 'Warning about /proc/self/statm should be logged exactly once');
    }

    public function testReadMemoryRssWarningContainsExpectedMessage(): void
    {
        if (is_readable('/proc/self/statm')) {
            self::markTestSkipped('/proc/self/statm is available on this platform');
        }

        $policy = $this->createPolicy();
        $policy->readMemoryRss();

        $logOutput = $this->getLogOutput();
        $lines = array_filter(explode("\n", trim($logOutput)));
        $lastLine = json_decode(end($lines), true);

        self::assertSame('warning', $lastLine['level']);
        self::assertStringContainsString('/proc/self/statm not available', $lastLine['message']);
        self::assertStringContainsString('MAX_MEMORY_RSS', $lastLine['message']);
    }
}
