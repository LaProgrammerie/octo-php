<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Exception;

use RuntimeException;

/**
 * Exception thrown when sending a job to the BlockingPool fails.
 *
 * Causes: pool process down, broken Unix socket, IPC write failure.
 * Maps to HTTP 502 Bad Gateway in runOrRespondError().
 */
final class BlockingPoolSendException extends RuntimeException {}
