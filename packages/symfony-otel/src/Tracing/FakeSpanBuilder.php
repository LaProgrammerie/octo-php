<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

/**
 * In-memory span builder for testing without the OTEL SDK.
 */
final class FakeSpanBuilder implements SpanBuilderInterface
{
    private int $spanKind = SpanKind::KIND_INTERNAL;

    /** @var array<string, string|int|float|bool> */
    private array $attributes = [];

    /** @var array<string, string>|null */
    private ?array $parentContext = null;

    public function __construct(
        private readonly string $name,
    ) {
    }

    public function setSpanKind(int $spanKind): self
    {
        $this->spanKind = $spanKind;
        return $this;
    }

    public function setAttribute(string $key, string|int|float|bool $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function setParent(array $parentContext): self
    {
        $this->parentContext = $parentContext;
        return $this;
    }

    public function startSpan(): SpanInterface
    {
        $span = new FakeSpan($this->name, $this->spanKind, $this->parentContext);

        foreach ($this->attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        return $span;
    }
}
