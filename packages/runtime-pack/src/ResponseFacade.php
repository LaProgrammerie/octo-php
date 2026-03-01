<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack;

/**
 * Wrapper around OpenSwoole\Http\Response that protects all write operations
 * via ResponseState. The application handler receives ONLY this facade,
 * never the raw OpenSwoole Response.
 *
 * Guarantees:
 * - end() can only be called once (via ResponseState.trySend())
 * - status() and header() are silently ignored after end()
 * - write() is silently ignored after end()
 * - The deadline timer and the handler share the same ResponseState
 * - status() updates ResponseState.statusCode for the access log
 * - end() sets statusCode to 200 if no status was explicitly defined
 */
final class ResponseFacade
{
    public function __construct(
        private readonly object $rawResponse,
        private readonly ResponseState $state,
        private readonly JsonLogger $logger,
    ) {
    }

    /**
     * Sets the HTTP status code. Ignored if the response has already been sent.
     * Updates ResponseState.statusCode for the access log.
     * Returns $this for chaining.
     */
    public function status(int $statusCode, string $reason = ''): self
    {
        if ($this->state->isSent()) {
            return $this;
        }
        $this->state->setStatusCode($statusCode);
        $this->rawResponse->status($statusCode, $reason);

        return $this;
    }

    /**
     * Adds an HTTP header. Ignored if the response has already been sent.
     * Returns $this for chaining.
     */
    public function header(string $key, string $value): self
    {
        if ($this->state->isSent()) {
            return $this;
        }
        $this->rawResponse->header($key, $value);

        return $this;
    }

    /**
     * Sends the response body and terminates the HTTP response.
     * Can only be called once. Subsequent calls are ignored and a warning is logged.
     * Sets statusCode to 200 in ResponseState if no status was explicitly defined (HTTP default).
     * Returns true if the response was sent, false otherwise.
     */
    public function end(string $content = ''): bool
    {
        if (!$this->state->trySend()) {
            $this->logger->warning('ResponseFacade::end() called after response already sent');

            return false;
        }
        // Bug fix: if no status was explicitly set via status(),
        // call setStatusCode(200) to "commit" the value in ResponseState.
        // Before this fix, a tautological `if` did nothing and the statusCode remained null
        // in ResponseState (getStatusCode() returned 200 by default, but the value
        // was never written — risk of confusion for ResponseState consumers).
        if (!$this->state->hasExplicitStatusCode()) {
            $this->state->setStatusCode(200);
        }
        try {
            $this->rawResponse->end($content);

            return true;
        } catch (\Throwable) {
            // Connection already closed client-side
            return false;
        }
    }

    /**
     * Writes data to the response (partial streaming).
     * Ignored if the response has already been terminated via end().
     */
    public function write(string $content): bool
    {
        if ($this->state->isSent()) {
            return false;
        }
        try {
            return $this->rawResponse->write($content);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Has the response already been sent? */
    public function isSent(): bool
    {
        return $this->state->isSent();
    }
}
