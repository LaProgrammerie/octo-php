<?php

declare(strict_types=1);

namespace Octo\SymfonyRealtime;

/**
 * Encapsulates SSE event sending via a write callable.
 *
 * In production, the write callable is typically ResponseFacade::write().
 *
 * Features:
 * - Periodic keep-alive (SSE comment `: keep-alive\n\n`, default 15s)
 * - Support for Last-Event-ID header for client reconnection
 */
final class SseStream
{
    private float $lastKeepAliveAt;
    private ?string $lastEventId;

    /** @var callable(string): bool */
    private $writeFn;

    /**
     * @param callable(string): bool $writeFn
     * @param int $keepAliveSeconds
     * @param string|null $lastEventId
     */
    public function __construct(
        callable $writeFn,
        private readonly int $keepAliveSeconds = 15,
        ?string $lastEventId = null,
    ) {
        $this->writeFn = $writeFn;
        $this->lastKeepAliveAt = microtime(true);
        $this->lastEventId = $lastEventId;
    }

    public function send(SseEvent $event): void
    {
        ($this->writeFn)($event->format());
        $this->lastKeepAliveAt = microtime(true);

        if ($event->id !== null) {
            $this->lastEventId = $event->id;
        }
    }

    public function sendKeepAlive(): void
    {
        ($this->writeFn)(": keep-alive\n\n");
        $this->lastKeepAliveAt = microtime(true);
    }

    public function shouldSendKeepAlive(): bool
    {
        return (microtime(true) - $this->lastKeepAliveAt) >= $this->keepAliveSeconds;
    }

    public function getLastEventId(): ?string
    {
        return $this->lastEventId;
    }
}
