<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Exception;

use RuntimeException;

/**
 * Exception thrown when a BlockingPool job exceeds its timeout.
 *
 * The job was sent to the pool but the response did not arrive
 * within the configured timeout. Maps to HTTP 504 Gateway Timeout
 * in runOrRespondError().
 */
final class BlockingPoolTimeoutException extends RuntimeException {}
