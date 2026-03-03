<?php

declare(strict_types=1);

namespace Octo\SymfonyRealtime;

/**
 * Helper for formatting and parsing SSE events conforming to the W3C spec.
 *
 * Output format:
 *   event: {event}\n
 *   data: {line1}\n
 *   data: {line2}\n
 *   id: {id}\n
 *   retry: {retry}\n
 *   \n
 *
 * Property: format() then parse() restores the original fields (round-trip).
 *
 * @see https://html.spec.whatwg.org/multipage/server-sent-events.html
 */
final readonly class SseEvent
{
    public function __construct(
        public string $data,
        public ?string $event = null,
        public ?string $id = null,
        public ?int $retry = null,
    ) {
    }

    /**
     * Formats the SSE event as W3C-compliant text.
     *
     * Multi-line data: each line is prefixed with "data: ".
     * Ends with double \n (empty line = event boundary).
     */
    public function format(): string
    {
        $lines = [];

        if ($this->event !== null) {
            $lines[] = 'event: ' . $this->event;
        }

        // Multi-line data: each line must be prefixed with "data: "
        foreach (explode("\n", $this->data) as $dataLine) {
            $lines[] = 'data: ' . $dataLine;
        }

        if ($this->id !== null) {
            $lines[] = 'id: ' . $this->id;
        }

        if ($this->retry !== null) {
            $lines[] = 'retry: ' . $this->retry;
        }

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * Parses an SSE text block back into an SseEvent.
     *
     * Used for round-trip property testing.
     */
    public static function parse(string $raw): self
    {
        $event = null;
        $dataLines = [];
        $id = null;
        $retry = null;

        foreach (explode("\n", rtrim($raw, "\n")) as $line) {
            if (str_starts_with($line, 'event: ')) {
                $event = substr($line, 7);
            } elseif (str_starts_with($line, 'data: ')) {
                $dataLines[] = substr($line, 6);
            } elseif ($line === 'data:') {
                // Empty data line (edge case: "data: " with empty value after trim)
                $dataLines[] = '';
            } elseif (str_starts_with($line, 'id: ')) {
                $id = substr($line, 4);
            } elseif (str_starts_with($line, 'retry: ')) {
                $retry = (int) substr($line, 7);
            }
        }

        return new self(
            data: implode("\n", $dataLines),
            event: $event,
            id: $id,
            retry: $retry,
        );
    }
}
