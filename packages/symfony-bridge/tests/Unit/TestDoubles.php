<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Unit;

use Octo\SymfonyBridge\ResetHookInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

// --- Shared test doubles ---

/**
 * Spy logger that records log entries for assertion.
 */
class SpyLogger extends NullLogger
{
    /** @var list<array{level: string, message: string, context: array}> */
    public array $logs = [];

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->logs[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function hasErrorContaining(string $substring): bool
    {
        foreach ($this->logs as $log) {
            if ($log['level'] === 'error' && str_contains($log['message'] . ' ' . ($log['context']['error'] ?? ''), $substring)) {
                return true;
            }
        }
        return false;
    }

    public function hasWarningContaining(string $substring): bool
    {
        foreach ($this->logs as $log) {
            if ($log['level'] === 'warning' && str_contains($log['message'], $substring)) {
                return true;
            }
        }
        return false;
    }
}

/**
 * Fake ResponseFacade that records all method calls for verification.
 */
class FakeResponseFacade
{
    public ?int $statusCode = null;
    /** @var list<array{string, string}> */
    public array $headers = [];
    /** @var list<string> */
    public array $writes = [];
    public bool $endCalled = false;
    public string $endContent = '';

    /** @var list<string> Ordered log of operations for sequence verification */
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
}

/**
 * Fake raw OpenSwoole response that records cookie() and sendfile() calls.
 */
class FakeRawSwooleResponse
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

// --- ResetManager test doubles ---

/**
 * Kernel implementing ResetInterface — strategy 1.
 */
class ResettableKernel implements HttpKernelInterface, ResetInterface
{
    public bool $resetCalled = false;

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response('OK');
    }

    public function reset(): void
    {
        $this->resetCalled = true;
    }
}

/**
 * Kernel implementing ResetInterface that throws on reset.
 */
class ThrowingResettableKernel implements HttpKernelInterface, ResetInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response('OK');
    }

    public function reset(): void
    {
        throw new \RuntimeException('Kernel reset exploded');
    }
}

/**
 * Kernel implementing ResetInterface with a configurable sleep to simulate slow resets.
 */
class SlowResettableKernel implements HttpKernelInterface, ResetInterface
{
    public function __construct(private readonly int $sleepUs = 60_000)
    {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response('OK');
    }

    public function reset(): void
    {
        usleep($this->sleepUs);
    }
}

/**
 * Kernel with getContainer() returning a container that has services_resetter — strategy 2.
 */
class KernelWithContainer implements HttpKernelInterface
{
    public function __construct(public readonly FakeContainer $container)
    {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response('OK');
    }

    public function getContainer(): FakeContainer
    {
        return $this->container;
    }
}

/**
 * Kernel implementing ResetInterface AND having a container with services_resetter.
 * Used to verify priority: ResetInterface wins.
 */
class ResettableKernelWithContainer implements HttpKernelInterface, ResetInterface
{
    public bool $resetCalled = false;
    public FakeContainer $container;

    public function __construct()
    {
        $this->container = new FakeContainer(['services_resetter' => new FakeServicesResetter()]);
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response('OK');
    }

    public function reset(): void
    {
        $this->resetCalled = true;
    }

    public function getContainer(): FakeContainer
    {
        return $this->container;
    }
}

/**
 * Bare kernel with no reset capability — strategy 3 (best-effort).
 */
class BareKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response('OK');
    }
}

/**
 * Fake DI container.
 */
class FakeContainer
{
    /** @var array<string, object> */
    private array $services;
    public ?FakeServicesResetter $servicesResetter;

    /** @param array<string, object> $services */
    public function __construct(array $services = [])
    {
        $this->services = $services;
        $this->servicesResetter = isset($services['services_resetter']) ? $services['services_resetter'] : null;
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    public function get(string $id): object
    {
        return $this->services[$id];
    }
}

/**
 * Fake services_resetter.
 */
class FakeServicesResetter
{
    public bool $resetCalled = false;

    public function reset(): void
    {
        $this->resetCalled = true;
    }
}

/**
 * Spy ResetHookInterface implementation.
 */
class SpyResetHook implements ResetHookInterface
{
    public bool $called = false;

    public function reset(): void
    {
        $this->called = true;
    }
}

/**
 * ResetHookInterface that throws.
 */
class FailingResetHook implements ResetHookInterface
{
    public function reset(): void
    {
        throw new \RuntimeException('Hook exploded');
    }
}

/**
 * Fake Doctrine Connection.
 */
class FakeDoctrineConnection
{
    public bool $rollbackCalled = false;

    public function __construct(private readonly bool $transactionActive = false)
    {
    }

    public function isTransactionActive(): bool
    {
        return $this->transactionActive;
    }

    public function rollBack(): void
    {
        $this->rollbackCalled = true;
    }
}

/**
 * Fake Doctrine EntityManager.
 */
class FakeEntityManager
{
    public bool $clearCalled = false;

    public function __construct(private readonly FakeDoctrineConnection $connection)
    {
    }

    public function getConnection(): FakeDoctrineConnection
    {
        return $this->connection;
    }

    public function clear(): void
    {
        $this->clearCalled = true;
    }
}

// --- HttpKernelAdapter test doubles ---

/**
 * Kernel that tracks lifecycle calls: handle, terminate, shutdown, boot.
 * Supports configurable responses and exceptions.
 */
class LifecycleTrackingKernel implements HttpKernelInterface, ResetInterface
{
    /** @var list<string> */
    public array $calls = [];
    public bool $resetCalled = false;
    private ?Response $responseToReturn;
    private ?\Throwable $exceptionToThrow;
    private int $bootCount = 0;

    public function __construct(
        ?Response $responseToReturn = null,
        ?\Throwable $exceptionToThrow = null,
    ) {
        $this->responseToReturn = $responseToReturn ?? new Response('OK', 200);
        $this->exceptionToThrow = $exceptionToThrow;
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->calls[] = 'handle';
        if ($this->exceptionToThrow !== null) {
            throw $this->exceptionToThrow;
        }
        return $this->responseToReturn;
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->calls[] = 'terminate';
    }

    public function reset(): void
    {
        $this->calls[] = 'reset';
        $this->resetCalled = true;
    }

    public function shutdown(): void
    {
        $this->calls[] = 'shutdown';
    }

    public function boot(): void
    {
        $this->calls[] = 'boot';
        $this->bootCount++;
    }

    public function getBootCount(): int
    {
        return $this->bootCount;
    }

    public function setResponse(Response $response): void
    {
        $this->responseToReturn = $response;
    }

    public function setException(?\Throwable $e): void
    {
        $this->exceptionToThrow = $e;
    }
}

/**
 * Fake OpenSwoole Request for testing.
 */
class FakeSwooleRequest
{
    /** @var array<string, string> */
    public array $header = [];
    /** @var array<string, mixed> */
    public array $server = [];
    /** @var array<string, string>|null */
    public ?array $get = null;
    /** @var array<string, string>|null */
    public ?array $post = null;
    /** @var array<string, string>|null */
    public ?array $cookie = null;
    /** @var array<string, mixed>|null */
    public ?array $files = null;
    private string $rawBody = '';

    public function __construct(
        string $method = 'GET',
        string $uri = '/',
        array $headers = [],
        string $body = '',
    ) {
        $this->server = [
            'request_method' => strtolower($method),
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

    public static function withRequestId(string $requestId, string $method = 'GET', string $uri = '/'): self
    {
        return new self($method, $uri, ['x-request-id' => $requestId]);
    }
}

/**
 * Fake OpenSwoole Response that records all operations.
 * Used as the raw swoole response in HttpKernelAdapter tests.
 */
class FakeSwooleResponse
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
        // no-op for tests
    }
}
