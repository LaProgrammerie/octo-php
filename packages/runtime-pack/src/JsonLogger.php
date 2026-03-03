<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

/**
 * PSR-3 logger writing NDJSON to a configurable stream (default: STDERR).
 *
 * Immutability by clone: withComponent() and withRequestId() return a new instance.
 * The original instance is never mutated.
 *
 * Format: {"timestamp":"RFC3339 UTC","level":"...","message":"...","component":"...","request_id":null,"extra":{}}
 * Each log entry is exactly one JSON line (NDJSON) for ELK/Loki/CloudWatch compatibility.
 */
final class JsonLogger implements LoggerInterface
{
    use LoggerTrait;

    private const VALID_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** Reserved context keys that are promoted to top-level fields. */
    private const RESERVED_KEYS = ['component', 'request_id'];

    private string $component = 'runtime';
    private ?string $requestId = null;

    /**
     * @param bool $production Whether the logger is in production mode
     * @param resource|null $stream Output stream (default: STDERR). Accepts any writable resource for testability.
     */
    public function __construct(
        private readonly bool $production = false,
        private $stream = null,
    ) {
        if ($this->stream === null) {
            $this->stream = \STDERR;
        }
    }

    /**
     * Writes a log entry as a single NDJSON line.
     *
     * Context keys 'component' and 'request_id' are silently removed from extra
     * (they are top-level fields set via withComponent/withRequestId).
     * In dev mode, a warning is emitted to stderr if these keys are found in context.
     *
     * @throws \Psr\Log\InvalidArgumentException If the level is not a valid PSR-3 level.
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $level = (string) $level;

        if (!\in_array($level, self::VALID_LEVELS, true)) {
            throw new \Psr\Log\InvalidArgumentException(
                \sprintf('Invalid log level: %s', $level),
            );
        }

        // Guard: warn in dev if reserved keys are in context
        $extra = $context;
        foreach (self::RESERVED_KEYS as $key) {
            if (\array_key_exists($key, $extra)) {
                if (!$this->production) {
                    \fwrite(\STDERR, \sprintf(
                        "[JsonLogger] Warning: context key '%s' is reserved and will be ignored. Use with%s() instead.\n",
                        $key,
                        \ucfirst(\str_replace('_', '', \ucwords($key, '_'))),
                    ));
                }
                unset($extra[$key]);
            }
        }

        $entry = [
            'timestamp' => \gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => (string) $message,
            'component' => $this->component,
            'request_id' => $this->requestId,
            'extra' => empty($extra) ? new \stdClass() : $extra,
        ];

        $json = \json_encode($entry, self::JSON_FLAGS);

        \fwrite($this->stream, $json . "\n");
    }

    /** Returns a new instance with the specified component. */
    public function withComponent(string $component): self
    {
        $clone = clone $this;
        $clone->component = $component;

        return $clone;
    }

    /** Returns a new instance with the specified request_id. */
    public function withRequestId(?string $requestId): self
    {
        $clone = clone $this;
        $clone->requestId = $requestId;

        return $clone;
    }
}
