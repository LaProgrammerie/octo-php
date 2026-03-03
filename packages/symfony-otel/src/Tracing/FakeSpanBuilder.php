<?php

declare(strict_types=1);

namespace Octo\SymfonyOtel\Tracing;

use Override;

/**
 * In-memory span builder for testing without the OTEL SDK.
 */
final class FakeSpanBuilder implements SpanBuilderInterface
{
    private int $spanKind = SpanKind::KIND_INTERNAL;

    /** @var array<string, bool|float|int|string> */
    private array $attributes = [];

    /** @var null|array<string, string> */
    private ?array $parentContext = null;

    public function __construct(
        private readonly string $name,
    ) {}

    #[Override]
    public function setSpanKind(int $spanKind): self
    {
        $this->spanKind = $spanKind;

        return $this;
    }

    #[Override]
    public function setAttribute(string $key, bool|float|int|string $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    #[Override]
    public function setParent(array $parentContext): self
    {
        $this->parentContext = $parentContext;

        return $this;
    }

    #[Override]
    public function startSpan(): SpanInterface
    {
        $span = new FakeSpan($this->name, $this->spanKind, $this->parentContext);

        foreach ($this->attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        return $span;
    }
}
