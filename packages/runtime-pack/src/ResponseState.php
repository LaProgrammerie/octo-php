<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack;

/**
 * Guarantees that only one HTTP response is sent per request.
 * Replaces the &$responded pattern (not coroutine-safe).
 * Safe for coroutine interleavings within a single worker
 * (single-threaded, no memory race conditions, but yields
 * can cause logical interleavings).
 *
 * Also stores the HTTP statusCode for the access log.
 * The statusCode is updated by ResponseFacade::status() and ResponseFacade::end(),
 * as well as by the deadline timer (408) and error handler (500) in ScopeRunner.
 */
final class ResponseState
{
    private bool $sent = false;
    private ?int $statusCode = null;

    /**
     * Atomically check-and-set: returns true if this call "wins" the right to send.
     * Subsequent calls return false.
     * Safe for coroutine interleavings within a single worker.
     */
    public function trySend(): bool
    {
        if ($this->sent) {
            return false;
        }
        $this->sent = true;

        return true;
    }

    public function isSent(): bool
    {
        return $this->sent;
    }

    /**
     * Sets the HTTP statusCode.
     * Called by ResponseFacade::status(), the deadline timer (408),
     * and the error handler (500).
     */
    public function setStatusCode(int $code): void
    {
        $this->statusCode = $code;
    }

    /**
     * Returns the stored HTTP statusCode.
     * If no statusCode has been explicitly set, returns 200 (HTTP default).
     */
    public function getStatusCode(): int
    {
        return $this->statusCode ?? 200;
    }

    /**
     * Indicates whether a statusCode has been explicitly set via setStatusCode().
     * Used by ResponseFacade::end() to know if it should commit the default 200.
     */
    public function hasExplicitStatusCode(): bool
    {
        return $this->statusCode !== null;
    }
}
