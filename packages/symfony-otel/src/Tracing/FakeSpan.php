<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

use Override;
use Throwable;

/**
 * In-memory span for testing without the OTEL SDK.
 *
 * Records all attributes, exceptions, and status changes for assertion.
 * NOT for production use — the real OTEL SDK span handles context propagation,
 * sampling, and export.
 */
final class FakeSpan implements SpanInterface
{
    /** @var array<string, bool|float|int|string> */
    private array $attributes = [];

    private int $statusCode = StatusCode::STATUS_UNSET;
    private ?string $statusDescription = null;

    /** @var list<Throwable> */
    private array $exceptions = [];

    private bool $ended = false;

    /**
     * @param null|array<string, string> $parentContext
     */
    public function __construct(
        private readonly string $name,
        private readonly int $kind,
        private readonly ?array $parentContext = null,
    ) {}

    #[Override]
    public function setAttribute(string $key, bool|float|int|string $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    #[Override]
    public function setStatus(int $code, ?string $description = null): self
    {
        $this->statusCode = $code;
        $this->statusDescription = $description;

        return $this;
    }

    #[Override]
    public function recordException(Throwable $exception): self
    {
        $this->exceptions[] = $exception;

        return $this;
    }

    #[Override]
    public function end(): void
    {
        $this->ended = true;
    }

    #[Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[Override]
    public function getKind(): int
    {
        return $this->kind;
    }

    #[Override]
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    #[Override]
    public function hasEnded(): bool
    {
        return $this->ended;
    }

    #[Override]
    public function getParentContext(): ?array
    {
        return $this->parentContext;
    }

    #[Override]
    public function getRecordedExceptions(): array
    {
        return $this->exceptions;
    }

    #[Override]
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getStatusDescription(): ?string
    {
        return $this->statusDescription;
    }
}
