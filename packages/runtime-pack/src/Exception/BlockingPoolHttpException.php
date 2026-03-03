<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Exception;

/**
 * Exception thrown by BlockingPool::runOrRespondError() after sending the HTTP response.
 *
 * Contains the HTTP status code so that ScopeRunner/RequestHandler can log
 * the correct status code without re-sending a response.
 *
 * Mapping:
 * - 503: Pool queue full (backpressure)
 * - 504: Job timeout (blocking job too slow)
 * - 502: Pool send failed (pool down/broken socket)
 * - 500: Job execution failed (RuntimeException)
 */
final class BlockingPoolHttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $httpStatusCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatusCode, $previous);
    }
}
