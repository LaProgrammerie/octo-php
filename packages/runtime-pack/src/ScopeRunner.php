<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Timer;
use Throwable;

/**
 * Orchestrates request handler execution within a managed scope.
 *
 * KEY POINT: ScopeRunner.runRequest() is SYNCHRONOUS (yield-ok).
 * It runs in the request coroutine provided by OpenSwoole (onRequest).
 * It does NOT create a coroutine via Coroutine::create().
 *
 * The handler runs directly in the request coroutine.
 * Timer::after() is used for the deadline — the timer callback runs
 * in the same worker event loop (not in a separate coroutine from the handler).
 * ResponseState protects against double-send between the timer callback and the handler.
 * ResponseState stores the statusCode for the access log.
 *
 * Concurrency semaphore (V1):
 * If maxConcurrentScopes > 0, a semaphore (bounded Channel) limits the number
 * of active scopes simultaneously in this worker. This prevents self-DDoS
 * when too many concurrent requests spawn scopes.
 * If the semaphore is full, the request receives an immediate 503 (no blocking).
 *
 * Sequence:
 * 1. Acquire semaphore (if maxConcurrentScopes > 0) — 503 if full
 * 2. Create ResponseState
 * 3. Arm deadline timer via Timer::after()
 * 4. Execute handler IN the request coroutine (no Coroutine::create)
 * 5. On deadline (timer callback): setStatusCode(408) BEFORE trySend() + Content-Type:application/json
 * 6. On exception: setStatusCode(500) BEFORE trySend() + Content-Type:application/json
 * 7. Finally: clear timer, decrement metrics, release semaphore
 * 8. Return — post-response operations run after in RequestHandler
 */
final class ScopeRunner
{
    /**
     * Concurrency semaphore per worker.
     * Implemented via a bounded Channel (capacity = maxConcurrentScopes)
     * pre-filled with N tokens.
     * pop() = acquire a token (non-blocking with timeout 0).
     * push() = release a token after use.
     * If the Channel is empty (all tokens acquired), pop() returns false → immediate 503.
     * null if maxConcurrentScopes = 0 (unlimited).
     */
    private ?Channel $concurrencySemaphore = null;

    public function __construct(
        private readonly MetricsCollector $metrics,
        private readonly JsonLogger $logger,
        private readonly int $maxConcurrentScopes = 0,
    ) {
        if ($maxConcurrentScopes > 0) {
            // Bounded Channel used as semaphore:
            // Pre-fill the channel with N tokens.
            // pop() = acquire a token (non-blocking with timeout 0).
            // push() = release a token.
            $this->concurrencySemaphore = new Channel($maxConcurrentScopes);
            for ($i = 0; $i < $maxConcurrentScopes; ++$i) {
                $this->concurrencySemaphore->push(true);
            }
        }
    }

    /**
     * Execute a request handler within a managed scope.
     * SYNCHRONOUS (yield-ok) — runs in the OpenSwoole request coroutine.
     * Returns after handler completion or deadline expiration.
     *
     * @param callable(Request, Response, ResponseState): void $handler
     */
    public function runRequest(
        callable $handler,
        Request $request,
        Response $rawResponse,
        string $requestId,
        float $timeoutSeconds,
    ): ResponseState {
        // Concurrency semaphore: acquire a slot (non-blocking)
        $semaphoreAcquired = false;
        if ($this->concurrencySemaphore !== null) {
            // pop() with timeout 0 = non-blocking. Returns false if channel is empty.
            $token = $this->concurrencySemaphore->pop(0.0);
            if ($token === false) {
                // Semaphore full → immediate 503 (no blocking, no scope created)
                $responseState = new ResponseState();
                $responseState->setStatusCode(503);
                if ($responseState->trySend()) {
                    try {
                        $rawResponse->status(503);
                        $rawResponse->header('Content-Type', 'application/json');
                        $rawResponse->header('Retry-After', '1');
                        $rawResponse->end('{"error":"Too many concurrent requests (scope limit reached)"}');
                    } catch (Throwable) {
                    }
                }
                $this->metrics->incrementScopeRejected();
                $this->logger->warning('ScopeRunner: scope rejected (semaphore full)', [
                    'request_id' => $requestId,
                    'max_concurrent_scopes' => $this->maxConcurrentScopes,
                ]);

                return $responseState;
            }
            $semaphoreAcquired = true;
        }

        $responseState = new ResponseState();
        $this->metrics->incrementInflightScopes();

        // Capture variables explicitly for the timer callback.
        // NO $this in use() — capture $metrics and $logger directly.
        $metrics = $this->metrics;
        $logger = $this->logger->withRequestId($requestId);

        // Timer deadline — runs in the same worker event loop
        $timerId = Timer::after(
            (int) ($timeoutSeconds * 1000),
            static function () use ($rawResponse, $responseState, $logger, $metrics, $timeoutSeconds): void {
                // Set statusCode BEFORE trySend() for log/metrics consistency
                // (avoids a transient state where trySend=true but statusCode not yet set)
                $responseState->setStatusCode(408);
                // Send 408 if nobody has responded yet
                if ($responseState->trySend()) {
                    try {
                        $rawResponse->status(408);
                        $rawResponse->header('Content-Type', 'application/json');
                        $rawResponse->end('{"error":"Request Timeout"}');
                    } catch (Throwable) {
                        // Connection already closed
                    }
                    $metrics->incrementCancelledRequests();
                    $logger->warning('Request cancelled: deadline exceeded', [
                        'timeout_seconds' => $timeoutSeconds,
                    ]);
                }
            },
        );

        try {
            // The handler runs IN the request coroutine (no Coroutine::create)
            $handler($request, $rawResponse, $responseState);
        } catch (Throwable $e) {
            // Uncaught exception in handler → 500
            // Set statusCode BEFORE trySend() for log/metrics consistency
            $responseState->setStatusCode(500);
            if ($responseState->trySend()) {
                try {
                    $rawResponse->status(500);
                    $rawResponse->header('Content-Type', 'application/json');
                    $rawResponse->end('{"error":"Internal Server Error"}');
                } catch (Throwable) {
                }
            }
            $logger->error('Handler exception', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            try {
                Timer::clear($timerId); // @phpstan-ignore arguments.count (extension reflection is wrong — clear() requires timerId)
            } catch (Throwable) {
            }
            $this->metrics->decrementInflightScopes();
            // Release semaphore if acquired
            if ($semaphoreAcquired) {
                $this->concurrencySemaphore->push(true);
            }
        }

        // Return ResponseState so RequestHandler can read the statusCode
        return $responseState;
    }
}
