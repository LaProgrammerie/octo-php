<?php

declare(strict_types=1);

namespace App\Tests\Unit\Handler;

use App\Handler\HomeHandler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HomeHandler.
 *
 * Validates: Requirements 6.2 — GET / → 200 {"message":"Hello, Async PHP!"}
 */
final class HomeHandlerTest extends TestCase
{
    public function testReturnsHelloJsonResponse(): void
    {
        $handler = new HomeHandler();

        $request = new class {
            public array $server = ['request_uri' => '/', 'request_method' => 'GET'];
        };

        $headers = [];
        $body = null;

        $response = new class ($headers, $body) {
            /** @var array<string, string> */
            private array $headers;
            private ?string $body;

            public function __construct(array &$headers, ?string &$body)
            {
                $this->headers = &$headers;
                $this->body = &$body;
            }

            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }

            public function end(string $content = ''): void
            {
                $this->body = $content;
            }
        };

        $handler($request, $response);

        self::assertSame('application/json', $headers['Content-Type']);
        self::assertNotNull($body);

        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['message' => 'Hello, Async PHP!'], $decoded);
    }
}
