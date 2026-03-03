<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

use Octo\RuntimePack\Exception\BlockingPoolFullException;
use Octo\RuntimePack\Exception\BlockingPoolHttpException;
use Octo\RuntimePack\Exception\BlockingPoolSendException;
use Octo\RuntimePack\Exception\BlockingPoolTimeoutException;
use OpenSwoole\Http\Response;
use RuntimeException;

/**
 * Standardized error mapping from BlockingPool exceptions to HTTP responses.
 *
 * Extracted as a standalone helper for testability (BlockingPool is final).
 * Used by BlockingPool::runOrRespondError() and available for direct use
 * in handlers that need custom error handling.
 *
 * Mapping:
 * - BlockingPoolFullException    → 503 + Retry-After:5 + Content-Type:application/json
 * - BlockingPoolTimeoutException → 504 + Content-Type:application/json
 * - BlockingPoolSendException    → 502 + Content-Type:application/json
 * - \RuntimeException            → 500 + Content-Type:application/json
 */
final class BlockingPoolErrorHandler
{
    /**
     * Execute a blocking pool job and convert errors to HTTP responses.
     *
     * @param BlockingPoolInterface $pool The pool to run the job on
     * @param string $jobName Job name
     * @param array<string, mixed> $payload Job data
     * @param object&Response $response Response object with status(), header(), end()
     * @param null|float $timeout Timeout in seconds
     *
     * @return mixed Job result on success
     *
     * @throws BlockingPoolHttpException On any pool error (after HTTP response is sent)
     */
    public static function runOrRespondError(
        BlockingPoolInterface $pool,
        string $jobName,
        array $payload,
        object $response,
        ?float $timeout = null,
    ): mixed {
        try {
            return $pool->run($jobName, $payload, $timeout);
        } catch (BlockingPoolFullException $e) {
            self::sendErrorResponse($response, 503, '{"error":"Service temporarily unavailable (pool saturated)"}', ['Retry-After' => '5']);

            throw new BlockingPoolHttpException(503, $e->getMessage(), $e);
        } catch (BlockingPoolTimeoutException $e) {
            self::sendErrorResponse($response, 504, '{"error":"Gateway Timeout (blocking job too slow)"}');

            throw new BlockingPoolHttpException(504, $e->getMessage(), $e);
        } catch (BlockingPoolSendException $e) {
            self::sendErrorResponse($response, 502, '{"error":"Bad Gateway (blocking pool unavailable)"}');

            throw new BlockingPoolHttpException(502, $e->getMessage(), $e);
        } catch (RuntimeException $e) {
            self::sendErrorResponse($response, 500, '{"error":"Internal Server Error (blocking job failed)"}');

            throw new BlockingPoolHttpException(500, $e->getMessage(), $e);
        }
    }

    /**
     * @param object&Response $response
     * @param array<string, string> $extraHeaders
     */
    private static function sendErrorResponse(object $response, int $status, string $body, array $extraHeaders = []): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json');
        foreach ($extraHeaders as $key => $value) {
            $response->header($key, $value);
        }
        $response->end($body);
    }
}
