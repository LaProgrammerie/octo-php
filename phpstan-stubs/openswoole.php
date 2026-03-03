<?php

declare(strict_types=1);

/**
 * OpenSwoole class stubs for PHPStan.
 *
 * Loaded via stubFiles in phpstan.neon.dist to override the extension's
 * reflection-based signatures with correct type information.
 *
 * Constants are in openswoole-constants.php (separate file because
 * global define() cannot coexist with namespace declarations).
 */

namespace OpenSwoole {
    class Timer
    {
        public static function after(int $ms, callable $callback): int
        {
        }
        public static function tick(int $ms, callable $callback): int
        {
        }
        public static function clear(int $timerId): bool
        {
        }
    }

    class Runtime
    {
        public static function enableCoroutine(int $flags = 0, int $type = 0): void
        {
        }
        public static function getHookFlags(): int
        {
        }
    }

    class Process
    {
        public static function signal(int $signo, callable $handler): bool
        {
        }
        /** @return string|false */
        public function read(int $bufferSize = 8192): string|false
        {
        }
        public function write(string $data): int|false
        {
        }
    }

    class Coroutine
    {
        public static function create(callable $fn, mixed ...$args): int|false
        {
        }
        public static function run(callable $fn, mixed ...$args): bool
        {
        }
        public static function getCid(): int
        {
        }
        public static function usleep(int $usec): void
        {
        }
    }
}

namespace OpenSwoole\Coroutine {
    class Channel
    {
        public int $errCode = 0;
        public function __construct(int $capacity = 1)
        {
        }
        public function push(mixed $data, float $timeout = -1): bool
        {
        }
        public function pop(float $timeout = -1): mixed
        {
        }
        public function length(): int
        {
        }
        public function close(): bool
        {
        }
    }
}

namespace OpenSwoole\Http {
    class Request
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
        public int $fd = 0;
        public function rawContent(): string|false
        {
        }
    }

    class Response
    {
        public function status(int $statusCode, string $reason = ''): bool
        {
        }
        public function header(string $key, string $value, bool $format = true): bool
        {
        }
        public function end(?string $content = ''): bool
        {
        }
        public function write(string $content): bool
        {
        }
        public function cookie(string $name, string $value = '', int $expires = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = ''): bool
        {
        }
        public function sendfile(string $filename, int $offset = 0, int $length = 0): bool
        {
        }
        public function push(string $data, int $opcode = 1, int $flags = 1): bool
        {
        }
        public function close(): bool
        {
        }
    }

    class Server
    {
        public function __construct(string $host, int $port = 0, int $mode = 0, int $sockType = 0)
        {
        }
        /** @param array<string, mixed> $settings */
        public function set(array $settings): void
        {
        }
        public function on(string $event, callable $callback): void
        {
        }
        public function start(): bool
        {
        }
        public function shutdown(): void
        {
        }
    }
}

namespace OpenSwoole\Process {
    class Pool
    {
        public function __construct(int $workerNum, int $ipcType = 0, int $msgQueueKey = 0, bool $enableCoroutine = false)
        {
        }
        public function getProcess(int $workerId = -1): \OpenSwoole\Process|false
        {
        }
    }
}
