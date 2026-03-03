<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use Octo\RuntimePack\JsonLogger;
use Octo\RuntimePack\RequestIdMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RequestIdMiddleware.
 *
 * Validates: Requirements 10.1, 10.2, 10.3, 10.5
 * Validates: Property 9, Property 10, Property 11
 *
 * Since OpenSwoole extension is not available in unit test context,
 * we use a simple stdClass stub mimicking the Request->header property.
 */
final class RequestIdMiddlewareTest extends TestCase
{
    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var resource */
    private $logStream;

    private JsonLogger $logger;

    protected function setUp(): void
    {
        $this->logStream = fopen('php://memory', 'r+');
        $this->logger = new JsonLogger(production: true, stream: $this->logStream);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->logStream)) {
            fclose($this->logStream);
        }
    }

    private function createMiddleware(): RequestIdMiddleware
    {
        return new RequestIdMiddleware($this->logger);
    }

    /**
     * Creates a stub mimicking OpenSwoole\Http\Request with a header property.
     *
     * @param array<string, string> $headers Lowercase header keys
     */
    private function createRequest(array $headers = []): object
    {
        $request = new \stdClass();
        $request->header = $headers;

        return $request;
    }

    private function readLogOutput(): string
    {
        rewind($this->logStream);

        return stream_get_contents($this->logStream);
    }

    private function readLogLines(): array
    {
        $output = trim($this->readLogOutput());
        if ($output === '') {
            return [];
        }

        return array_map(
            static fn(string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            explode("\n", $output),
        );
    }

    // ── Requirement 10.2: No header → generates UUIDv4 ──

    public function testNoHeaderGeneratesUuidV4(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest();

        $result = $middleware->resolve($request);

        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $result);
        $this->assertSame(36, strlen($result));
    }

    public function testNullHeaderPropertyGeneratesUuidV4(): void
    {
        $middleware = $this->createMiddleware();
        $request = new \stdClass();
        $request->header = null;

        $result = $middleware->resolve($request);

        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $result);
    }

    // ── Requirement 10.1: Valid header → reused as-is ──

    public function testValidHeaderIsReusedAsIs(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['x-request-id' => 'my-custom-request-id-123']);

        $result = $middleware->resolve($request);

        $this->assertSame('my-custom-request-id-123', $result);
    }

    public function testValidHeaderExactly128CharsIsAccepted(): void
    {
        $middleware = $this->createMiddleware();
        $value = str_repeat('a', 128);
        $request = $this->createRequest(['x-request-id' => $value]);

        $result = $middleware->resolve($request);

        $this->assertSame($value, $result);
    }

    public function testValidHeaderWithPrintableAsciiChars(): void
    {
        $middleware = $this->createMiddleware();
        // All printable ASCII: space (32) through tilde (126)
        $value = 'abc-123_DEF.456 ~!@#$%^&*()';
        $request = $this->createRequest(['x-request-id' => $value]);

        $result = $middleware->resolve($request);

        $this->assertSame($value, $result);
    }

    // ── Requirement 10.5: Header > 128 chars → rejected ──

    public function testHeaderTooLongIsRejected(): void
    {
        $middleware = $this->createMiddleware();
        $value = str_repeat('x', 129);
        $request = $this->createRequest(['x-request-id' => $value]);

        $result = $middleware->resolve($request);

        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $result);
        $this->assertNotSame($value, $result);
    }

    public function testHeaderTooLongLogsWarning(): void
    {
        $middleware = $this->createMiddleware();
        $value = str_repeat('x', 200);
        $request = $this->createRequest(['x-request-id' => $value]);

        $middleware->resolve($request);

        $logs = $this->readLogLines();
        $this->assertCount(1, $logs);
        $this->assertSame('warning', $logs[0]['level']);
        $this->assertStringContainsString('X-Request-Id rejected', $logs[0]['message']);
    }

    // ── Requirement 10.5: Non-ASCII chars → rejected ──

    public function testHeaderWithNonAsciiIsRejected(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['x-request-id' => "request-id-\xC0\xC1"]);

        $result = $middleware->resolve($request);

        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $result);
    }

    public function testHeaderWithUnicodeIsRejected(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['x-request-id' => 'request-éàü-日本語']);

        $result = $middleware->resolve($request);

        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $result);
    }

    public function testHeaderWithControlCharsIsRejected(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['x-request-id' => "request\x00id"]);

        $result = $middleware->resolve($request);

        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $result);
    }

    public function testHeaderWithTabIsRejected(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['x-request-id' => "request\tid"]);

        $result = $middleware->resolve($request);

        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $result);
    }

    public function testHeaderWithNonAsciiLogsWarning(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['x-request-id' => "invalid-\xFF-chars"]);

        $middleware->resolve($request);

        $logs = $this->readLogLines();
        $this->assertCount(1, $logs);
        $this->assertSame('warning', $logs[0]['level']);
    }

    // ── Empty header → generates new UUIDv4 ──

    public function testEmptyHeaderGeneratesUuidV4(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['x-request-id' => '']);

        $result = $middleware->resolve($request);

        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $result);
    }

    public function testEmptyHeaderDoesNotLogWarning(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['x-request-id' => '']);

        $middleware->resolve($request);

        $output = trim($this->readLogOutput());
        $this->assertSame('', $output, 'Empty header should silently generate a new ID without warning');
    }

    // ── Property 10: UUIDv4 format validation ──

    public function testGeneratedUuidV4HasCorrectFormat(): void
    {
        $middleware = $this->createMiddleware();

        // Generate multiple UUIDs to verify format consistency
        for ($i = 0; $i < 20; $i++) {
            $request = $this->createRequest();
            $result = $middleware->resolve($request);

            $this->assertMatchesRegularExpression(
                self::UUID_V4_PATTERN,
                $result,
                "Generated UUID #{$i} does not match UUIDv4 format: {$result}",
            );
            $this->assertSame(36, strlen($result));
        }
    }

    public function testGeneratedUuidsAreUnique(): void
    {
        $middleware = $this->createMiddleware();
        $uuids = [];

        for ($i = 0; $i < 50; $i++) {
            $request = $this->createRequest();
            $uuids[] = $middleware->resolve($request);
        }

        $this->assertCount(50, array_unique($uuids), 'Generated UUIDs should be unique');
    }

    // ── Property 9: resolve() never returns empty string ──

    public function testResolveNeverReturnsEmptyString(): void
    {
        $middleware = $this->createMiddleware();

        // No header
        $this->assertNotSame('', $middleware->resolve($this->createRequest()));

        // Empty header
        $this->assertNotSame('', $middleware->resolve($this->createRequest(['x-request-id' => ''])));

        // Invalid header
        $this->assertNotSame('', $middleware->resolve($this->createRequest(['x-request-id' => str_repeat('x', 200)])));

        // Valid header
        $this->assertNotSame('', $middleware->resolve($this->createRequest(['x-request-id' => 'valid-id'])));
    }

    // ── Security: Warning log does NOT contain raw invalid input ──

    public function testWarningLogDoesNotContainRawInput(): void
    {
        $middleware = $this->createMiddleware();
        $maliciousInput = '<script>alert("xss")</script>' . str_repeat('A', 200);
        $request = $this->createRequest(['x-request-id' => $maliciousInput]);

        $middleware->resolve($request);

        $output = $this->readLogOutput();
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringNotContainsString('alert', $output);
        $this->assertStringNotContainsString($maliciousInput, $output);
    }

    public function testWarningLogDoesNotContainNonAsciiRawInput(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['x-request-id' => "malicious-\xFF\xFE-payload"]);

        $middleware->resolve($request);

        $output = $this->readLogOutput();
        $this->assertStringNotContainsString("\xFF", $output);
        $this->assertStringNotContainsString('malicious', $output);
    }

    // ── Edge case: header with space (ASCII 32) is valid ──

    public function testHeaderWithSpaceIsValid(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['x-request-id' => 'request id with spaces']);

        $result = $middleware->resolve($request);

        $this->assertSame('request id with spaces', $result);
    }

    // ── Edge case: header with tilde (ASCII 126) is valid ──

    public function testHeaderWithTildeIsValid(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['x-request-id' => 'id~with~tildes']);

        $result = $middleware->resolve($request);

        $this->assertSame('id~with~tildes', $result);
    }

    // ── Edge case: header with DEL (ASCII 127) is invalid ──

    public function testHeaderWithDelCharIsRejected(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['x-request-id' => "id-with-\x7F-del"]);

        $result = $middleware->resolve($request);

        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $result);
    }
}
