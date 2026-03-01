<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack;

use AsyncPlatform\RuntimePack\Exception\BlockingPoolFullException;
use AsyncPlatform\RuntimePack\Exception\BlockingPoolHttpException;
use AsyncPlatform\RuntimePack\Exception\BlockingPoolSendException;
use AsyncPlatform\RuntimePack\Exception\BlockingPoolTimeoutException;

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
     * @param array $payload Job data
     * @param object $response Response object with status(), header(), end()
     * @param float|null $timeout Timeout in seconds
     * @return mixed Job result on success
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
            $response->status(503);
            $response->header('Content-Type', 'application/json');
            $response->header('Retry-After', '5');
            $response->end('{"error":"Service temporarily unavailable (pool saturated)"}');
            throw new BlockingPoolHttpException(503, $e->getMessage(), $e);
        } catch (BlockingPoolTimeoutException $e) {
            $response->status(504);
            $response->header('Content-Type', 'application/json');
            $response->end('{"error":"Gateway Timeout (blocking job too slow)"}');
            throw new BlockingPoolHttpException(504, $e->getMessage(), $e);
        } catch (BlockingPoolSendException $e) {
            $response->status(502);
            $response->header('Content-Type', 'application/json');
            $response->end('{"error":"Bad Gateway (blocking pool unavailable)"}');
            throw new BlockingPoolHttpException(502, $e->getMessage(), $e);
        } catch (\RuntimeException $e) {
            $response->status(500);
            $response->header('Content-Type', 'application/json');
            $response->end('{"error":"Internal Server Error (blocking job failed)"}');
            throw new BlockingPoolHttpException(500, $e->getMessage(), $e);
        }
    }
}
