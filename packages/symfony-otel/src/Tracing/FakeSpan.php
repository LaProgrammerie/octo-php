<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

/**
 * In-memory span for testing without the OTEL SDK.
 *
 * Records all attributes, exceptions, and status changes for assertion.
 * NOT for production use — the real OTEL SDK span handles context propagation,
 * sampling, and export.
 */
final class FakeSpan implements SpanInterface
{
    /** @var array<string, string|int|float|bool> */
    private array $attributes = [];

    private int $statusCode = StatusCode::STATUS_UNSET;
    private ?string $statusDescription = null;

    /** @var list<\Throwable> */
    private array $exceptions = [];

    private bool $ended = false;

    /**
     * @param array<string, string>|null $parentContext
     */
    public function __construct(
        private readonly string $name,
        private readonly int $kind,
        private readonly ?array $parentContext = null,
    ) {
    }

    public function setAttribute(string $key, string|int|float|bool $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function setStatus(int $code, ?string $description = null): self
    {
        $this->statusCode = $code;
        $this->statusDescription = $description;
        return $this;
    }

    public function recordException(\Throwable $exception): self
    {
        $this->exceptions[] = $exception;
        return $this;
    }

    public function end(): void
    {
        $this->ended = true;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKind(): int
    {
        return $this->kind;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function hasEnded(): bool
    {
        return $this->ended;
    }

    public function getParentContext(): ?array
    {
        return $this->parentContext;
    }

    public function getRecordedExceptions(): array
    {
        return $this->exceptions;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getStatusDescription(): ?string
    {
        return $this->statusDescription;
    }
}
