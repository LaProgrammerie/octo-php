<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

/**
 * Mirrors OpenTelemetry\API\Trace\StatusCode constants.
 */
final class StatusCode
{
    public const STATUS_UNSET = 0;
    public const STATUS_OK = 1;
    public const STATUS_ERROR = 2;

    private function __construct()
    {
    }
}
