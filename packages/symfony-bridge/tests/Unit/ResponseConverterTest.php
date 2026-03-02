<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge\Tests\Unit;

require_once __DIR__ . '/TestDoubles.php';

use AsyncPlatform\SymfonyBridge\ResponseConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
    public function it_converts_status_code(): void
    {
        $response = new Response('OK', 201);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $this->assertSame(201, $this->facade->statusCode);
    }

    #[Test]
    public function it_converts_simple_headers(): void
    {
        $response = new Response('body');
        $response->headers->set('X-Custom', 'value1');
        $response->headers->set('Content-Type', 'application/json');

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $this->assertContains(['X-Custom', 'value1'], $this->facade->headers);
        $this->assertContains(['Content-Type', 'application/json'], $this->facade->headers);
    }

    #[Test]
    public function it_converts_multi_valued_headers(): void
    {
        $response = new Response('body');
        $response->headers->set('X-Multi', ['val1', 'val2']);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $multiHeaders = array_filter(
            $this->facade->headers,
            fn(array $h) => $h[0] === 'X-Multi',
        );
        $values = array_map(fn(array $h) => $h[1], array_values($multiHeaders));
        $this->assertContains('val1', $values);
        $this->assertContains('val2', $values);
    }

    #[Test]
    public function it_converts_cookies_with_all_attributes(): void
    {
        $response = new Response('body');
        $cookie = Cookie::create('session')
            ->withValue('abc123')
            ->withExpires(1700000000)
            ->withPath('/app')
            ->withDomain('.example.com')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite('Lax');
        $response->headers->setCookie($cookie);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $this->assertCount(1, $this->rawResponse->cookies);
        $c = $this->rawResponse->cookies[0];
        $this->assertSame('session', $c['name']);
        $this->assertSame('abc123', $c['value']);
        $this->assertSame(1700000000, $c['expires']);
        $this->assertSame('/app', $c['path']);
        $this->assertSame('.example.com', $c['domain']);
        $this->assertTrue($c['secure']);
        $this->assertTrue($c['httpOnly']);
        $this->assertSame('lax', $c['sameSite']);
    }

    #[Test]
    public function it_writes_body_via_end(): void
    {
        $response = new Response('Hello World', 200);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $this->assertSame('Hello World', $this->facade->endContent);
        $this->assertTrue($this->facade->endCalled);
    }

    #[Test]
    public function it_removes_server_and_x_powered_by_headers(): void
    {
        $response = new Response('body');
        $response->headers->set('Server', 'Apache/2.4');
        $response->headers->set('X-Powered-By', 'PHP/8.3');
        $response->headers->set('X-Custom', 'keep');

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $headerNames = array_map(fn(array $h) => $h[0], $this->facade->headers);
        $this->assertNotContains('Server', $headerNames);
        $this->assertNotContains('X-Powered-By', $headerNames);
        $this->assertContains('X-Custom', $headerNames);
    }

    #[Test]
    public function it_preserves_content_length(): void
    {
        $body = 'Hello World';
        $response = new Response($body, 200);
        $response->headers->set('Content-Length', (string) \strlen($body));

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $contentLengthHeaders = array_filter(
            $this->facade->headers,
            fn(array $h) => $h[0] === 'Content-Length',
        );
        $this->assertNotEmpty($contentLengthHeaders);
        $values = array_map(fn(array $h) => $h[1], array_values($contentLengthHeaders));
        $this->assertContains((string) \strlen($body), $values);
    }

    // --- Streaming ---

    #[Test]
    public function it_streams_chunks_via_write(): void
    {
        $response = new StreamedResponse(function (): void {
            echo 'chunk1';
            echo 'chunk2';
            echo 'chunk3';
        }, 200, ['Content-Type' => 'text/plain']);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $this->assertNotEmpty($this->facade->writes);
        // All chunks should be written
        $allWritten = implode('', $this->facade->writes);
        $this->assertStringContainsString('chunk1', $allWritten);
        $this->assertStringContainsString('chunk2', $allWritten);
        $this->assertStringContainsString('chunk3', $allWritten);
        // end('') called after streaming
        $this->assertTrue($this->facade->endCalled);
        $this->assertSame('', $this->facade->endContent);
    }

    #[Test]
    public function it_sets_headers_before_first_write_for_streamed(): void
    {
        $response = new StreamedResponse(function (): void {
            echo 'data';
        }, 200, ['Content-Type' => 'text/plain']);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        // Status and headers should be set before any write
        $this->assertSame(200, $this->facade->statusCode);
        // The status was set before writes (verified by order tracking)
        $this->assertTrue($this->facade->statusSetBeforeFirstWrite());
    }

    #[Test]
    public function it_logs_error_and_ends_on_callback_exception(): void
    {
        $response = new StreamedResponse(function (): void {
            echo 'partial';
            throw new \RuntimeException('Callback failed');
        }, 200);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        // Error should be logged
        $this->assertTrue($this->spyLogger->hasErrorContaining('Callback failed'));
        // Response should still be terminated
        $this->assertTrue($this->facade->endCalled);
    }

    // --- SSE ---

    #[Test]
    public function it_disables_compression_for_sse(): void
    {
        $response = new StreamedResponse(function (): void {
            echo "data: hello\n\n";
        }, 200, ['Content-Type' => 'text/event-stream']);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        // Raw response should have buffering disabled
        $this->assertContains(
            ['X-Accel-Buffering', 'no'],
            $this->rawResponse->headers,
        );
        $this->assertContains(
            ['Cache-Control', 'no-cache'],
            $this->rawResponse->headers,
        );
    }

    #[Test]
    public function it_flushes_sse_chunks_immediately(): void
    {
        $response = new StreamedResponse(function (): void {
            echo "data: event1\n\n";
            echo "data: event2\n\n";
        }, 200, ['Content-Type' => 'text/event-stream']);

        $this->converter->convert($response, $this->facade, $this->rawResponse);

        $allWritten = implode('', $this->facade->writes);
        $this->assertStringContainsString('data: event1', $allWritten);
        $this->assertStringContainsString('data: event2', $allWritten);
    }

    // --- BinaryFileResponse ---

    #[Test]
    public function it_uses_sendfile_for_binary_file_response(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_binary_');
        file_put_contents($tmpFile, 'binary content');

        try {
            $response = new BinaryFileResponse($tmpFile);

            $this->converter->convert($response, $this->facade, $this->rawResponse);

            $this->assertSame($tmpFile, $this->rawResponse->sentFile);
            $this->assertSame(200, $this->facade->statusCode);
        } finally {
            @unlink($tmpFile);
        }
    }
}
