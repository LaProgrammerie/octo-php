<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge\Tests\Property;

use AsyncPlatform\SymfonyBridge\ResponseConverter;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Property 4: Streaming invariant
 *
 * **Validates: Requirements 3.9, 3.10, 3.12, 3.13, 3.14**
 *
 * For any StreamedResponse with a callback producing N chunks,
 * the conversion SHALL call write() for each chunk with headers set before the first write.
 * For SSE responses, compression and buffering SHALL be disabled.
 */
final class StreamingInvariantTest extends TestCase
{
    use TestTrait;

    private ResponseConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new ResponseConverter(new NullLogger());
    }

    #[Test]
    public function writeCalledForEachChunkWithHeadersBeforeFirstWrite(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::choose(1, 10),
        )->then(function (int $chunkCount): void {
            $chunks = [];
            for ($i = 0; $i < $chunkCount; $i++) {
                $chunks[] = 'chunk_' . $i . '_' . bin2hex(random_bytes(4));
            }

            $response = new StreamedResponse(function () use ($chunks): void {
                foreach ($chunks as $chunk) {
                    echo $chunk;
                }
            }, 200, ['Content-Type' => 'text/plain']);

            $facade = new StreamingRecordingFacade();
            $raw = new StreamingRecordingRawResponse();

            $this->converter->convert($response, $facade, $raw);

            // All chunks should appear in writes (ob_start may batch them)
            $allWritten = implode('', $facade->writes);
            foreach ($chunks as $chunk) {
                self::assertStringContainsString(
                    $chunk,
                    $allWritten,
                    "Chunk '{$chunk}' not found in writes",
                );
            }

            // Status must be set before first write
            self::assertNotNull($facade->statusCode, 'Status code not set');
            self::assertTrue(
                $facade->statusSetBeforeFirstWrite(),
                'Status must be set before first write()',
            );

            // Headers must be set before first write
            self::assertTrue(
                $facade->headersSetBeforeFirstWrite(),
                'Headers must be set before first write()',
            );

            // end('') must be called after all writes
            self::assertTrue($facade->endCalled, 'end() must be called');
            self::assertSame('', $facade->endContent, 'end() must be called with empty string');
        });
    }

    #[Test]
    public function sseDisablesCompressionAndBuffering(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::choose(1, 5),
        )->then(function (int $eventCount): void {
            $events = [];
            for ($i = 0; $i < $eventCount; $i++) {
                $events[] = "data: event_{$i}\n\n";
            }

            $response = new StreamedResponse(function () use ($events): void {
                foreach ($events as $event) {
                    echo $event;
                }
            }, 200, ['Content-Type' => 'text/event-stream']);

            $facade = new StreamingRecordingFacade();
            $raw = new StreamingRecordingRawResponse();

            $this->converter->convert($response, $facade, $raw);

            // SSE: X-Accel-Buffering: no must be set on raw response
            self::assertContains(
                ['X-Accel-Buffering', 'no'],
                $raw->headers,
                'X-Accel-Buffering: no must be set for SSE',
            );

            // SSE: Cache-Control: no-cache must be set on raw response
            self::assertContains(
                ['Cache-Control', 'no-cache'],
                $raw->headers,
                'Cache-Control: no-cache must be set for SSE',
            );

            // All events should be written
            $allWritten = implode('', $facade->writes);
            foreach ($events as $event) {
                self::assertStringContainsString(
                    trim($event),
                    $allWritten,
                    "SSE event not found in writes",
                );
            }
        });
    }
}

/**
 * Recording facade with operation ordering for streaming verification.
 */
class StreamingRecordingFacade
{
    public ?int $statusCode = null;
    /** @var list<array{string, string}> */
    public array $headers = [];
    /** @var list<string> */
    public array $writes = [];
    public bool $endCalled = false;
    public string $endContent = '';

    /** @var list<string> Ordered log of operations */
    private array $operationLog = [];

    public function status(int $code): self
    {
        $this->statusCode = $code;
        $this->operationLog[] = 'status';
        return $this;
    }

    public function header(string $key, string $value): self
    {
        $this->headers[] = [$key, $value];
        $this->operationLog[] = 'header';
        return $this;
    }

    public function write(string $content): bool
    {
        $this->writes[] = $content;
        $this->operationLog[] = 'write';
        return true;
    }

    public function end(string $content = ''): bool
    {
        $this->endCalled = true;
        $this->endContent = $content;
        $this->operationLog[] = 'end';
        return true;
    }

    public function isSent(): bool
    {
        return $this->endCalled;
    }

    public function statusSetBeforeFirstWrite(): bool
    {
        $statusIdx = array_search('status', $this->operationLog, true);
        $writeIdx = array_search('write', $this->operationLog, true);

        if ($statusIdx === false) {
            return false;
        }
        if ($writeIdx === false) {
            return true;
        }

        return $statusIdx < $writeIdx;
    }

    public function headersSetBeforeFirstWrite(): bool
    {
        $firstHeaderIdx = array_search('header', $this->operationLog, true);
        $firstWriteIdx = array_search('write', $this->operationLog, true);

        if ($firstHeaderIdx === false) {
            return true; // No headers to check
        }
        if ($firstWriteIdx === false) {
            return true; // No writes
        }

        return $firstHeaderIdx < $firstWriteIdx;
    }
}

/**
 * Recording raw response for streaming PBT.
 */
class StreamingRecordingRawResponse
{
    /** @var list<array{name: string, value: string, expires: int, path: string, domain: string, secure: bool, httpOnly: bool, sameSite: string}> */
    public array $cookies = [];
    public ?string $sentFile = null;
    /** @var list<array{string, string}> */
    public array $headers = [];

    public function cookie(
        string $name,
        string $value,
        int $expires,
        string $path,
        string $domain,
        bool $secure,
        bool $httpOnly,
        string $sameSite,
    ): void {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
        ];
    }

    public function sendfile(string $path): void
    {
        $this->sentFile = $path;
    }

    public function header(string $key, string $value): void
    {
        $this->headers[] = [$key, $value];
    }
}
