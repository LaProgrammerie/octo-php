<?php

declare(strict_types=1);

namespace Octo\SymfonyRealtime;

/**
 * Immutable DTO containing WebSocket connection information.
 *
 * Provides send() and close() methods that delegate to closures
 * injected at construction time (bound to the underlying OpenSwoole response).
 */
final readonly class WebSocketContext
{
    /**
     * @param int                $connectionId Unique connection identifier
     * @param string             $requestId    Request ID for tracing
     * @param array<string, string> $headers   Original request headers
     * @param \Closure(string): void $sendFn   Sends data to the client
     * @param \Closure(): void       $closeFn  Closes the connection
     */
    public function __construct(
        public int $connectionId,
        public string $requestId,
        public array $headers,
        private \Closure $sendFn,
        private \Closure $closeFn,
    ) {
    }

    public function send(string $data): void
    {
        ($this->sendFn)($data);
    }

    public function close(): void
    {
        ($this->closeFn)();
    }
}
