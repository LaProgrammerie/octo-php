<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use DateTimeImmutable;
use DateTimeZone;
use Octo\RuntimePack\JsonLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

use function is_resource;

/**
 * Unit tests for JsonLogger — PSR-3 NDJSON logger.
 *
 * Validates: Requirements 7.1, 7.2, 7.3, 1.6
 */
final class JsonLoggerTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $this->stream = fopen('php://memory', 'r+');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    // ── PSR-3 interface compliance ──

    public function testImplementsLoggerInterface(): void
    {
        $logger = $this->createLogger();
        self::assertInstanceOf(LoggerInterface::class, $logger);
    }

    // ── NDJSON format (Requirement 7.1) ──

    public function testOutputIsSingleJsonLine(): void
    {
        $logger = $this->createLogger();
        $logger->info('test message');

        $output = $this->readOutput();
        $lines = explode("\n", mb_rtrim($output, "\n"));
        self::assertCount(1, $lines, 'Each log must be exactly one line (NDJSON)');

        // Must be valid JSON
        $decoded = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
    }

    public function testContainsAllRequiredFields(): void
    {
        $logger = $this->createLogger();
        $logger->info('hello');

        $entry = $this->readJsonLine();

        self::assertArrayHasKey('timestamp', $entry);
        self::assertArrayHasKey('level', $entry);
        self::assertArrayHasKey('message', $entry);
        self::assertArrayHasKey('component', $entry);
        self::assertArrayHasKey('request_id', $entry);
        self::assertArrayHasKey('extra', $entry);
    }

    // ── Timestamp format (RFC3339 UTC) ──

    public function testTimestampIsRfc3339Utc(): void
    {
        $logger = $this->createLogger();
        $logger->info('test');

        $entry = $this->readJsonLine();
        $ts = $entry['timestamp'];

        // Must match RFC3339 UTC pattern: YYYY-MM-DDTHH:MM:SSZ
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $ts,
            'Timestamp must be RFC3339 UTC format',
        );

        // Must parse as valid date
        $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $ts, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $dt);
    }

    // ── Level field ──

    #[DataProvider('provideAllPsr3LevelsDelegateToLogCases')]
    public function testAllPsr3LevelsDelegateToLog(string $level): void
    {
        $logger = $this->createLogger();
        $logger->{$level}('test message');

        $entry = $this->readJsonLine();
        self::assertSame($level, $entry['level']);
        self::assertSame('test message', $entry['message']);
    }

    public static function provideAllPsr3LevelsDelegateToLogCases(): iterable
    {
        return [
            'emergency' => [LogLevel::EMERGENCY],
            'alert' => [LogLevel::ALERT],
            'critical' => [LogLevel::CRITICAL],
            'error' => [LogLevel::ERROR],
            'warning' => [LogLevel::WARNING],
            'notice' => [LogLevel::NOTICE],
            'info' => [LogLevel::INFO],
            'debug' => [LogLevel::DEBUG],
        ];
    }

    public function testInvalidLevelThrowsException(): void
    {
        $logger = $this->createLogger();

        $this->expectException(InvalidArgumentException::class);
        $logger->log('invalid_level', 'test');
    }

    // ── Component field (Requirement 7.1) ──

    public function testDefaultComponentIsRuntime(): void
    {
        $logger = $this->createLogger();
        $logger->info('test');

        $entry = $this->readJsonLine();
        self::assertSame('runtime', $entry['component']);
    }

    public function testWithComponentReturnsNewInstance(): void
    {
        $logger = $this->createLogger();
        $scoped = $logger->withComponent('http');

        self::assertNotSame($logger, $scoped);
    }

    public function testWithComponentSetsComponent(): void
    {
        $logger = $this->createLogger()->withComponent('http');
        $logger->info('test');

        $entry = $this->readJsonLine();
        self::assertSame('http', $entry['component']);
    }

    public function testOriginalLoggerUnchangedAfterWithComponent(): void
    {
        $original = $this->createLogger();
        $original->withComponent('http');
        $original->info('test');

        $entry = $this->readJsonLine();
        self::assertSame('runtime', $entry['component'], 'Original logger must not be mutated');
    }

    // ── Request ID field ──

    public function testRequestIdDefaultsToNull(): void
    {
        $logger = $this->createLogger();
        $logger->info('test');

        $entry = $this->readJsonLine();
        self::assertNull($entry['request_id']);
    }

    public function testWithRequestIdReturnsNewInstance(): void
    {
        $logger = $this->createLogger();
        $scoped = $logger->withRequestId('abc-123');

        self::assertNotSame($logger, $scoped);
    }

    public function testWithRequestIdSetsRequestId(): void
    {
        $logger = $this->createLogger()->withRequestId('req-42');
        $logger->info('test');

        $entry = $this->readJsonLine();
        self::assertSame('req-42', $entry['request_id']);
    }

    public function testOriginalLoggerUnchangedAfterWithRequestId(): void
    {
        $original = $this->createLogger();
        $original->withRequestId('req-42');
        $original->info('test');

        $entry = $this->readJsonLine();
        self::assertNull($entry['request_id'], 'Original logger must not be mutated');
    }

    public function testWithRequestIdNullResetsToNull(): void
    {
        $logger = $this->createLogger()->withRequestId('req-42')->withRequestId(null);
        $logger->info('test');

        $entry = $this->readJsonLine();
        self::assertNull($entry['request_id']);
    }

    // ── Extra field ──

    public function testExtraIsEmptyObjectWhenNoContext(): void
    {
        $logger = $this->createLogger();
        $logger->info('test');

        $output = mb_trim($this->readOutput());
        // Verify it's {} not [] — important for ingestion stability
        self::assertStringContainsString('"extra":{}', $output);
    }

    public function testExtraContainsContextKeys(): void
    {
        $logger = $this->createLogger();
        $logger->info('test', ['foo' => 'bar', 'count' => 42]);

        $entry = $this->readJsonLine();
        self::assertSame('bar', $entry['extra']['foo']);
        self::assertSame(42, $entry['extra']['count']);
    }

    // ── Reserved keys guard (component, request_id in context) ──

    public function testContextComponentKeyIsIgnored(): void
    {
        $logger = $this->createLogger(production: true);
        $logger->info('test', ['component' => 'should-be-ignored', 'other' => 'kept']);

        $entry = $this->readJsonLine();
        // component top-level should be 'runtime' (default), not overridden by context
        self::assertSame('runtime', $entry['component']);
        // 'component' should NOT appear in extra
        self::assertArrayNotHasKey('component', (array) $entry['extra']);
        // other keys should be preserved
        self::assertSame('kept', $entry['extra']['other']);
    }

    public function testContextRequestIdKeyIsIgnored(): void
    {
        $logger = $this->createLogger(production: true);
        $logger->info('test', ['request_id' => 'should-be-ignored']);

        $entry = $this->readJsonLine();
        self::assertNull($entry['request_id']);
        self::assertArrayNotHasKey('request_id', (array) $entry['extra']);
    }

    // ── Special characters in message (Requirement 7.1, Task 3.4) ──

    public function testSpecialCharactersInMessageAreProperlyEncoded(): void
    {
        $logger = $this->createLogger();
        $logger->info("Line1\nLine2\tTabbed \"quoted\" and \\backslash");

        $output = mb_trim($this->readOutput());
        // Must be single line (no literal newlines breaking NDJSON)
        $lines = explode("\n", $output);
        self::assertCount(1, $lines, 'Newlines in message must be JSON-escaped, not literal');

        // Must be valid JSON
        $entry = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("Line1\nLine2\tTabbed \"quoted\" and \\backslash", $entry['message']);
    }

    public function testUnicodeCharactersInMessage(): void
    {
        $logger = $this->createLogger();
        $logger->info('Héllo wörld 日本語 🚀');

        $entry = $this->readJsonLine();
        self::assertSame('Héllo wörld 日本語 🚀', $entry['message']);

        // With JSON_UNESCAPED_UNICODE, the raw output should contain the actual characters
        $output = mb_trim($this->readOutput());
        self::assertStringContainsString('Héllo wörld 日本語 🚀', $output);
    }

    public function testSlashesAreNotEscaped(): void
    {
        $logger = $this->createLogger();
        $logger->info('path/to/file');

        $output = mb_trim($this->readOutput());
        // JSON_UNESCAPED_SLASHES means / should appear as-is
        self::assertStringContainsString('path/to/file', $output);
        self::assertStringNotContainsString('path\/to\/file', $output);
    }

    public function testNullBytesInMessage(): void
    {
        $logger = $this->createLogger();
        $logger->info("before\x00after");

        $output = mb_trim($this->readOutput());
        // Must still be valid JSON (single line)
        $lines = explode("\n", $output);
        self::assertCount(1, $lines);
        $entry = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($entry['message']);
    }

    // ── Stringable message support ──

    public function testStringableMessage(): void
    {
        $logger = $this->createLogger();
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable message';
            }
        };

        $logger->info($stringable);

        $entry = $this->readJsonLine();
        self::assertSame('stringable message', $entry['message']);
    }

    // ── Chaining with*() methods ──

    public function testChainingWithComponentAndRequestId(): void
    {
        $logger = $this->createLogger()
            ->withComponent('http')
            ->withRequestId('req-99')
        ;

        $logger->info('chained');

        $entry = $this->readJsonLine();
        self::assertSame('http', $entry['component']);
        self::assertSame('req-99', $entry['request_id']);
    }

    private function createLogger(bool $production = false): JsonLogger
    {
        return new JsonLogger($production, $this->stream);
    }

    private function readOutput(): string
    {
        rewind($this->stream);

        return stream_get_contents($this->stream);
    }

    private function readJsonLine(): array
    {
        $output = mb_trim($this->readOutput());
        self::assertNotEmpty($output, 'Expected log output but got empty string');

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
