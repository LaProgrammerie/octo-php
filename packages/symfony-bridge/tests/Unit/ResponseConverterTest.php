<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Unit;

require_once __DIR__ . '/TestDoubles.php';

use Octo\SymfonyBridge\ResponseConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[CoversClass(ResponseConverter::class)]
final class ResponseConverterTest extends TestCase
{
    private ResponseConverter $converter;
    private FakeResponseFacade $facade;
    private FakeRawSwooleResponse $rawResponse;
    private SpyLogger $spyLogger;

    protected function setUp(): void
    {
        $this->spyLogger = new SpyLogger();
        $this->converter = new ResponseConverter($this->spyLogger);
        $this->facade = new FakeResponseFacade();
        $this->rawResponse = new FakeRawSwooleResponse();
    }

    // --- Standard conversion ---

    #[Test]
    public function itConvertsStatusCode(): void
    {
        $response = new Response('OK', 201);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        self::assertSame(201, $this->facade->statusCode);
    }

    #[Test]
    public function itConvertsSimpleHeaders(): void
    {
        $response = new Response('body');
        $response->headers->set('X-Custom', 'value1');
        $response->headers->set('Content-Type', 'application/json');

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        self::assertContains(['X-Custom', 'value1'], $this->facade->headers);
        self::assertContains(['Content-Type', 'application/json'], $this->facade->headers);
    }

    #[Test]
    public function itConvertsMultiValuedHeaders(): void
    {
        $response = new Response('body');
        $response->headers->set('X-Multi', ['val1', 'val2']);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $multiHeaders = array_filter(
            $this->facade->headers,
            static fn (array $h) => $h[0] === 'X-Multi',
        );
        $values = array_map(static fn (array $h) => $h[1], array_values($multiHeaders));
        self::assertContains('val1', $values);
        self::assertContains('val2', $values);
    }

    #[Test]
    public function itConvertsCookiesWithAllAttributes(): void
    {
        $response = new Response('body');
        $cookie = Cookie::create('session')
            ->withValue('abc123')
            ->withExpires(1700000000)
            ->withPath('/app')
            ->withDomain('.example.com')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite('Lax')
        ;
        $response->headers->setCookie($cookie);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        self::assertCount(1, $this->rawResponse->cookies);
        $c = $this->rawResponse->cookies[0];
        self::assertSame('session', $c['name']);
        self::assertSame('abc123', $c['value']);
        self::assertSame(1700000000, $c['expires']);
        self::assertSame('/app', $c['path']);
        self::assertSame('.example.com', $c['domain']);
        self::assertTrue($c['secure']);
        self::assertTrue($c['httpOnly']);
        self::assertSame('lax', $c['sameSite']);
    }

    #[Test]
    public function itWritesBodyViaEnd(): void
    {
        $response = new Response('Hello World', 200);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        self::assertSame('Hello World', $this->facade->endContent);
        self::assertTrue($this->facade->endCalled);
    }

    #[Test]
    public function itRemovesServerAndXPoweredByHeaders(): void
    {
        $response = new Response('body');
        $response->headers->set('Server', 'Apache/2.4');
        $response->headers->set('X-Powered-By', 'PHP/8.3');
        $response->headers->set('X-Custom', 'keep');

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $headerNames = array_map(static fn (array $h) => $h[0], $this->facade->headers);
        self::assertNotContains('Server', $headerNames);
        self::assertNotContains('X-Powered-By', $headerNames);
        self::assertContains('X-Custom', $headerNames);
    }

    #[Test]
    public function itPreservesContentLength(): void
    {
        $body = 'Hello World';
        $response = new Response($body, 200);
        $response->headers->set('Content-Length', (string) mb_strlen($body));

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $contentLengthHeaders = array_filter(
            $this->facade->headers,
            static fn (array $h) => $h[0] === 'Content-Length',
        );
        self::assertNotEmpty($contentLengthHeaders);
        $values = array_map(static fn (array $h) => $h[1], array_values($contentLengthHeaders));
        self::assertContains((string) mb_strlen($body), $values);
    }

    // --- Streaming ---

    #[Test]
    public function itStreamsChunksViaWrite(): void
    {
        $response = new StreamedResponse(static function (): void {
            echo 'chunk1';
            echo 'chunk2';
            echo 'chunk3';
        }, 200, ['Content-Type' => 'text/plain']);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        self::assertNotEmpty($this->facade->writes);
        // All chunks should be written
        $allWritten = implode('', $this->facade->writes);
        self::assertStringContainsString('chunk1', $allWritten);
        self::assertStringContainsString('chunk2', $allWritten);
        self::assertStringContainsString('chunk3', $allWritten);
        // end('') called after streaming
        self::assertTrue($this->facade->endCalled);
        self::assertSame('', $this->facade->endContent);
    }

    #[Test]
    public function itSetsHeadersBeforeFirstWriteForStreamed(): void
    {
        $response = new StreamedResponse(static function (): void {
            echo 'data';
        }, 200, ['Content-Type' => 'text/plain']);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        // Status and headers should be set before any write
        self::assertSame(200, $this->facade->statusCode);
        // The status was set before writes (verified by order tracking)
        self::assertTrue($this->facade->statusSetBeforeFirstWrite());
    }

    #[Test]
    public function itLogsErrorAndEndsOnCallbackException(): void
    {
        $response = new StreamedResponse(static function (): void {
            echo 'partial';

            throw new RuntimeException('Callback failed');
        }, 200);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        // Error should be logged
        self::assertTrue($this->spyLogger->hasErrorContaining('Callback failed'));
        // Response should still be terminated
        self::assertTrue($this->facade->endCalled);
    }

    // --- SSE ---

    #[Test]
    public function itDisablesCompressionForSse(): void
    {
        $response = new StreamedResponse(static function (): void {
            echo "data: hello\n\n";
        }, 200, ['Content-Type' => 'text/event-stream']);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        // Raw response should have buffering disabled
        self::assertContains(
            ['X-Accel-Buffering', 'no'],
            $this->rawResponse->headers,
        );
        self::assertContains(
            ['Cache-Control', 'no-cache'],
            $this->rawResponse->headers,
        );
    }

    #[Test]
    public function itFlushesSseChunksImmediately(): void
    {
        $response = new StreamedResponse(static function (): void {
            echo "data: event1\n\n";
            echo "data: event2\n\n";
        }, 200, ['Content-Type' => 'text/event-stream']);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $allWritten = implode('', $this->facade->writes);
        self::assertStringContainsString('data: event1', $allWritten);
        self::assertStringContainsString('data: event2', $allWritten);
    }

    // --- BinaryFileResponse ---

    #[Test]
    public function itUsesSendfileForBinaryFileResponse(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_binary_');
        file_put_contents($tmpFile, 'binary content');

        try {
            $response = new BinaryFileResponse($tmpFile);

            $this->converter->convert($response, $this->facade, $this->rawResponse);

            self::assertSame($tmpFile, $this->rawResponse->sentFile);
            self::assertSame(200, $this->facade->statusCode);
        } finally {
            @unlink($tmpFile);
        }
    }
}
