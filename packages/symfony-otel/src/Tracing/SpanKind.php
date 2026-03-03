<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

/**
 * Mirrors OpenTelemetry\API\Trace\SpanKind constants.
 */
final class SpanKind
{
    public const KIND_INTERNAL = 0;
    public const KIND_SERVER = 1;
    public const KIND_CLIENT = 2;
    public const KIND_PRODUCER = 3;
    public const KIND_CONSUMER = 4;

    private function __construct()
    {
    }
}
