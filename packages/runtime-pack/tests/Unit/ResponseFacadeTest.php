<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack\Tests\Unit;

use AsyncPlatform\RuntimePack\JsonLogger;
use AsyncPlatform\RuntimePack\ResponseFacade;
use AsyncPlatform\RuntimePack\ResponseState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ResponseFacade.
 *
 * Uses a simple mock object for the raw OpenSwoole Response since
 * ResponseFacade accepts `object` (no ext-openswoole needed for unit tests).
 */
final class ResponseFacadeTest extends TestCase
{
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

    // --- 19.3: end() without status() → statusCode=200 in ResponseState (not null) ---

    public function testEndWithoutStatusSetsStatusCode200InResponseState(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        // No status() call before end()
        $result = $facade->end('hello');

        $this->assertTrue($result);
        // The bug fix: statusCode is explicitly set to 200, not left as null
        $this->assertSame(200, $state->getStatusCode());
        $this->assertTrue($state->hasExplicitStatusCode());
    }

    // --- 19.4: end() with status(201) → statusCode=201 preserved (not overwritten by 200) ---

    public function testEndWithStatus201PreservesStatusCode(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->status(201);
        $facade->end('created');

        $this->assertSame(201, $state->getStatusCode());
        $this->assertTrue($state->hasExplicitStatusCode());
    }

    public function testEndWithStatus404PreservesStatusCode(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->status(404);
        $facade->end('not found');

        $this->assertSame(404, $state->getStatusCode());
    }

    // --- end() twice → false + warning ---

    public function testEndTwiceReturnsFalseAndLogsWarning(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        [$logger, $stream] = $this->createLoggerWithStream();
        $facade = new ResponseFacade($raw, $state, $logger);

        $this->assertTrue($facade->end('first'));
        $this->assertFalse($facade->end('second'));

        // Verify warning was logged
        rewind($stream);
        $logOutput = stream_get_contents($stream);
        $this->assertStringContainsString('ResponseFacade::end() called after response already sent', $logOutput);
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
        $this->assertSame(200, $state->getStatusCode());
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
        $headerCalls = array_filter($raw->calls, fn($c) => $c[0] === 'header');
        $this->assertEmpty($headerCalls);
    }

    // --- write() after end() → ignored ---

    public function testWriteAfterEndReturnsFalse(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->end('done');
        $this->assertFalse($facade->write('more data'));
    }

    // --- status() delegates to raw response ---

    public function testStatusDelegatesToRawResponse(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $result = $facade->status(201, 'Created');

        $this->assertSame($facade, $result); // Chaining
        $this->assertContains(['status', 201, 'Created'], $raw->calls);
        $this->assertSame(201, $state->getStatusCode());
    }

    // --- header() delegates to raw response ---

    public function testHeaderDelegatesToRawResponse(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $result = $facade->header('Content-Type', 'application/json');

        $this->assertSame($facade, $result); // Chaining
        $this->assertContains(['header', 'Content-Type', 'application/json'], $raw->calls);
    }

    // --- end() delegates to raw response ---

    public function testEndDelegatesToRawResponse(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->end('body content');

        $this->assertContains(['end', 'body content'], $raw->calls);
    }

    // --- isSent() ---

    public function testIsSentFalseInitially(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $this->assertFalse($facade->isSent());
    }

    public function testIsSentTrueAfterEnd(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->end();
        $this->assertTrue($facade->isSent());
    }

    // --- 19.5: Access log reflects correct statusCode in all cases ---

    public function testAccessLogReflectsStatusCode200WhenNoExplicitStatus(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->end('hello');

        // Access log reads from ResponseState.getStatusCode()
        $this->assertSame(200, $state->getStatusCode());
        $this->assertTrue($state->hasExplicitStatusCode());
    }

    public function testAccessLogReflectsStatusCode201WhenExplicitlySet(): void
    {
        $raw = $this->createRawResponse();
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $facade->status(201);
        $facade->end('created');

        $this->assertSame(201, $state->getStatusCode());
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
        $this->assertSame(500, $state->getStatusCode());
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
        $this->assertFalse($facade->end('late'));

        // Access log reads 408 from ResponseState
        $this->assertSame(408, $state->getStatusCode());
    }

    // --- end() with raw response throwing (connection closed) ---

    public function testEndReturnsFalseWhenRawResponseThrows(): void
    {
        $raw = new class {
            public function end(string $content = ''): void
            {
                throw new \RuntimeException('Connection reset by peer');
            }

            public function status(int $code, string $reason = ''): void
            {
            }
            public function header(string $key, string $value): void
            {
            }
        };
        $state = new ResponseState();
        $facade = new ResponseFacade($raw, $state, $this->createLogger());

        $result = $facade->end('body');

        // end() catches the exception and returns false
        $this->assertFalse($result);
        // But statusCode is still committed (200 default)
        $this->assertSame(200, $state->getStatusCode());
        $this->assertTrue($state->hasExplicitStatusCode());
    }
}
