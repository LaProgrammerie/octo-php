<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Exception;

/**
 * Exception thrown when the BlockingPool outbound queue is full.
 *
 * Indicates backpressure — the pool cannot accept more jobs.
 * Maps to HTTP 503 Service Unavailable in runOrRespondError().
 */
final class BlockingPoolFullException extends \RuntimeException
{
}
