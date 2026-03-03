<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use Octo\RuntimePack\JsonLogger;
use Octo\RuntimePack\ResponseFacade;
use Octo\RuntimePack\ResponseState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for ResponseFacade.
 *
 * Uses a simple mock object for the raw OpenSwoole Response since
 * ResponseFacade accepts `object` (no ext-openswoole needed for unit tests).
 */
final class ResponseFacadeTest extends TestCase
{
    // --- 19.3: end() without status() → statusCode=200 in ResponseState (not null) ---

    public function testEndWithoutStatusSetsStatusCode200InResponseState(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        // No status() call before end()
        $result = $facade->end('hello');

        self::assertTrue($result);
        // The bug fix: statusCode is explicitly set to 200, not left as null
        self::assertSame(200, $state->getStatusCode());
        self::assertTrue($state->hasExplicitStatusCode());
    }

    // --- 19.4: end() with status(201) → statusCode=201 preserved (not overwritten by 200) ---

    public function testEndWithStatus201PreservesStatusCode(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->status(201);
        $facade->end('created');

        self::assertSame(201, $state->getStatusCode());
        self::assertTrue($state->hasExplicitStatusCode());
    }

    public function testEndWithStatus404PreservesStatusCode(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->status(404);
        $facade->end('not found');

        self::assertSame(404, $state->getStatusCode());
    }

    // --- end() twice → false + warning ---

    public function testEndTwiceReturnsFalseAndLogsWarning(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        [$logger, $stream] = $this->createLoggerWithStream();
        $facade = new ResponseFacade($raw, $state, $logger);

        self::assertTrue($facade->end('first'));
        self::assertFalse($facade->end('second'));

        // Verify warning was logged
        rewind($stream);
        $logOutput = stream_get_contents($stream);
        self::assertStringContainsString('ResponseFacade::end() called after response already sent', $logOutput);
    }

    // --- status() after end() → ignored ---

    public function testStatusAfterEndIsIgnored(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->end('done');
        $facade->status(500); // Should be ignored

        // statusCode should remain 200 (set by end()), not 500
        self::assertSame(200, $state->getStatusCode());
    }

    // --- header() after end() → ignored ---

    public function testHeaderAfterEndIsIgnored(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->end('done');
        $facade->header('X-Custom', 'value'); // Should be ignored

        // Only the end() call should be in the raw response calls
        $headerCalls = array_filter($raw->calls, static fn ($c) => $c[0] === 'header');
        self::assertEmpty($headerCalls);
    }

    // --- write() after end() → ignored ---

    public function testWriteAfterEndReturnsFalse(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->end('done');
        self::assertFalse($facade->write('more data'));
    }

    // --- status() delegates to raw response ---

    public function testStatusDelegatesToRawResponse(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $result = $facade->status(201, 'Created');

        self::assertSame($facade, $result); // Chaining
        self::assertContains(['status', 201, 'Created'], $raw->calls);
        self::assertSame(201, $state->getStatusCode());
    }

    // --- header() delegates to raw response ---

    public function testHeaderDelegatesToRawResponse(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $result = $facade->header('Content-Type', 'application/json');

        self::assertSame($facade, $result); // Chaining
        self::assertContains(['header', 'Content-Type', 'application/json'], $raw->calls);
    }

    // --- end() delegates to raw response ---

    public function testEndDelegatesToRawResponse(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->end('body content');

        self::assertContains(['end', 'body content'], $raw->calls);
    }

    // --- isSent() ---

    public function testIsSentFalseInitially(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        self::assertFalse($facade->isSent());
    }

    public function testIsSentTrueAfterEnd(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->end();
        self::assertTrue($facade->isSent());
    }

    // --- 19.5: Access log reflects correct statusCode in all cases ---

    public function testAccessLogReflectsStatusCode200WhenNoExplicitStatus(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->end('hello');

        // Access log reads from ResponseState.getStatusCode()
        self::assertSame(200, $state->getStatusCode());
        self::assertTrue($state->hasExplicitStatusCode());
    }

    public function testAccessLogReflectsStatusCode201WhenExplicitlySet(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->status(201);
        $facade->end('created');

        self::assertSame(201, $state->getStatusCode());
    }

    public function testAccessLogReflectsStatusCode500WhenSetExternally(): void
    {
        // Simulates ScopeRunner setting 500 on exception BEFORE trySend
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $state->setStatusCode(500);
        $state->trySend(); // ScopeRunner wins the send

        // Access log reads from ResponseState
        self::assertSame(500, $state->getStatusCode());
    }

    public function testAccessLogReflectsStatusCode408WhenTimerWins(): void
    {
        // Simulates deadline timer setting 408 BEFORE trySend
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $state->setStatusCode(408);
        $state->trySend(); // Timer wins the send

        // Handler tries end() but loses
        self::assertFalse($facade->end('late'));

        // Access log reads 408 from ResponseState
        self::assertSame(408, $state->getStatusCode());
    }

    // --- end() with raw response throwing (connection closed) ---

    public function testEndReturnsFalseWhenRawResponseThrows(): void
    {
        $raw = new class {
            public function end(string $content = ''): void
            {
                throw new RuntimeException('Connection reset by peer');
            }

            public function status(int $code, string $reason = ''): void {}

            public function header(string $key, string $value): void {}
        };
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $result = $facade->end('body');

        // end() catches the exception and returns false
        self::assertFalse($result);
        // But statusCode is still committed (200 default)
        self::assertSame(200, $state->getStatusCode());
        self::assertTrue($state->hasExplicitStatusCode());
    }

    private function createRawResponse(): object
    {
        return new class {
            public array $calls = [];

            public function status(int $code, string $reason = ''): void
            {
                $this->calls[] = ['status', $code, $reason];
            }

            public function header(string $key, string $value): void
            {
                $this->calls[] = ['header', $key, $value];
            }

            public function end(string $content = ''): void
            {
                $this->calls[] = ['end', $content];
            }

            public function write(string $content): bool
            {
                $this->calls[] = ['write', $content];

                return true;
            }
        };
    }

    private function createLogger(): JsonLogger
    {
        $stream = fopen('php://memory', 'r+');

        return new JsonLogger(production: false, stream: $stream);
    }

    private function createLoggerWithStream(): array
    {
        $stream = fopen('php://memory', 'r+');
        $logger = new JsonLogger(production: false, stream: $stream);

        return [$logger, $stream];
    }
}
