<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

use Throwable;

/**
 * Abstraction over OpenTelemetry\API\Trace\SpanInterface.
 */
interface SpanInterface
{
    public function setAttribute(string $key, bool|float|int|string $value): self;

    public function setStatus(int $code, ?string $description = null): self;

    public function recordException(Throwable $exception): self;

    public function end(): void;

    /**
     * Returns the span name.
     */
    public function getName(): string;

    /**
     * Returns the span kind (SERVER=1, INTERNAL=3).
     */
    public function getKind(): int;

    /**
     * Returns all attributes set on this span.
     *
     * @return array<string, bool|float|int|string>
     */
    public function getAttributes(): array;

    /**
     * Returns true if end() has been called.
     */
    public function hasEnded(): bool;

    /**
     * Returns the parent context if set.
     *
     * @return null|array<string, string>
     */
    public function getParentContext(): ?array;

    /**
     * Returns recorded exceptions.
     *
     * @return list<Throwable>
     */
    public function getRecordedExceptions(): array;

    /**
     * Returns the status code (0=UNSET, 1=OK, 2=ERROR).
     */
    public function getStatusCode(): int;
}
