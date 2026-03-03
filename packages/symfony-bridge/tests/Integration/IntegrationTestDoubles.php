<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Integration;

use Octo\SymfonyBridge\ResetHookInterface;
use Psr\Log\NullLogger;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Shared test doubles for integration tests.
 * These simulate OpenSwoole objects and Symfony kernels without requiring
 * the OpenSwoole extension or a real Symfony application boot.
 */

// --- Spy Logger ---

final class IntegrationSpyLogger extends NullLogger
{
    /** @var list<array{level: string, message: string, context: array}> */
    public array $logs = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->logs[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function hasLogMatching(string $level, string $substring): bool
    {
        foreach ($this->logs as $log) {
            if ($log['level'] === $level && str_contains($log['message'], $substring)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{level: string, message: string, context: array}> */
    public function getLogsForLevel(string $level): array
    {
        return array_values(array_filter(
            $this->logs,
            static fn (array $log) => $log['level'] === $level,
        ));
    }
}

// --- Mini HttpKernel (test double implementing HttpKernelInterface) ---

final class MiniHttpKernel implements HttpKernelInterface, ResetInterface
{
    public int $handleCount = 0;
    public int $terminateCount = 0;
    public int $resetCount = 0;
    private ?Response $fixedResponse;

    public function __construct(?Response $fixedResponse = null)
    {
        $this->fixedResponse = $fixedResponse ?? new Response('Hello from MiniKernel', 200, ['Content-Type' => 'text/plain']);
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        ++$this->handleCount;

        return $this->fixedResponse;
    }

    public function terminate(Request $request, Response $response): void
    {
        ++$this->terminateCount;
    }

    public function reset(): void
    {
        ++$this->resetCount;
    }

    public function setResponse(Response $response): void
    {
        $this->fixedResponse = $response;
    }
}

// --- Counting Reset Hook ---

final class CountingResetHook implements ResetHookInterface
{
    public int $resetCount = 0;

    public function reset(): void
    {
        ++$this->resetCount;
    }
}

// --- Fake OpenSwoole Request ---

final class IntegrationFakeSwooleRequest
{
    /** @var array<string, string> */
    public array $header = [];

    /** @var array<string, mixed> */
    public array $server = [];

    /** @var null|array<string, string> */
    public ?array $get = null;

    /** @var null|array<string, string> */
    public ?array $post = null;

    /** @var null|array<string, string> */
    public ?array $cookie = null;

    /** @var null|array<string, mixed> */
    public ?array $files = null;
    public int $fd = 0;
    private string $rawBody;

    public function __construct(
        string $method = 'GET',
        string $uri = '/',
        array $headers = [],
        string $body = '',
    ) {
        $this->server = [
            'request_method' => mb_strtolower($method),
            'request_uri' => $uri,
            'query_string' => '',
            'server_protocol' => 'HTTP/1.1',
            'server_port' => 8080,
            'remote_addr' => '127.0.0.1',
            'remote_port' => 12345,
            'request_time' => time(),
            'request_time_float' => microtime(true),
        ];
        $this->header = $headers;
        $this->rawBody = $body;
    }

    public function rawContent(): string
    {
        return $this->rawBody;
    }

    public static function get(string $uri = '/', string $requestId = ''): self
    {
        $headers = $requestId !== '' ? ['x-request-id' => $requestId] : [];

        return new self('GET', $uri, $headers);
    }

    public static function post(string $uri, string $body, string $contentType = 'application/json'): self
    {
        return new self('POST', $uri, ['content-type' => $contentType], $body);
    }

    public static function wsUpgrade(string $uri = '/'): self
    {
        return new self('GET', $uri, [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'x-request-id' => 'ws-' . bin2hex(random_bytes(4)),
        ]);
    }
}

// --- Fake OpenSwoole Response (records all operations) ---

final class IntegrationFakeSwooleResponse
{
    public ?int $statusCode = null;

    /** @var array<string, string> */
    public array $headers = [];

    /** @var list<array{name: string, value: string, expires: int, path: string, domain: string, secure: bool, httpOnly: bool, sameSite: string}> */
    public array $cookies = [];

    /** @var list<string> */
    public array $writes = [];
    public bool $endCalled = false;
    public string $endContent = '';

    /** @var list<string> */
    public array $pushes = [];
    public bool $closed = false;

    public function status(int $code, string $reason = ''): void
    {
        $this->statusCode = $code;
    }

    public function header(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    public function write(string $content): bool
    {
        $this->writes[] = $content;

        return true;
    }

    public function end(string $content = ''): void
    {
        $this->endCalled = true;
        $this->endContent = $content;
    }

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
        $this->cookies[] = compact('name', 'value', 'expires', 'path', 'domain', 'secure', 'httpOnly', 'sameSite');
    }

    public function sendfile(string $path): void
    {
        // no-op
    }

    public function push(string $data): void
    {
        $this->pushes[] = $data;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isSent(): bool
    {
        return $this->endCalled;
    }

    public function getBody(): string
    {
        return $this->endContent;
    }

    public function getAllWrittenContent(): string
    {
        return implode('', $this->writes) . $this->endContent;
    }
}
