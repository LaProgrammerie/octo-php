<?php

declare(strict_types=1);

namespace Octo\SymfonyRealtime\Tests\Property;

use Eris\Generators;
use Eris\TestTrait;
use Octo\SymfonyRealtime\SseEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Property 13: SSE event formatting.
 *
 * **Validates: Requirements 13.1, 13.2**
 *
 * For any SseEvent with random data (including multi-line), event, id, retry:
 * - format() produces W3C-compliant SSE text
 * - Ends with double \n (event boundary)
 * - Multi-line data has each line prefixed with "data: "
 * - Optional fields only appear when set
 */
final class SseEventFormattingTest extends TestCase
{
    use TestTrait;

    #[Test]
    public function formatAlwaysEndsWithDoubleNewline(): void
    {
        $this->limitTo(200);

        $this->forAll(
            Generators::string(),
            Generators::elements([null, 'message', 'update', 'ping']),
            Generators::elements([null, '1', '42', 'evt-abc']),
            Generators::elements([null, 0, 1000, 3000, 5000]),
        )->then(static function (string $data, ?string $event, ?string $id, ?int $retry): void {
            // Filter out data containing \r to avoid cross-platform issues
            $data = str_replace("\r", '', $data);

            $sseEvent = new SseEvent(
                data: $data,
                event: $event,
                id: $id,
                retry: $retry,
            );

            $formatted = $sseEvent->format();

            self::assertTrue(
                str_ends_with($formatted, "\n\n"),
                'SSE event must end with double newline (event boundary)',
            );
        });
    }

    #[Test]
    public function multiLineDataHasEachLinePrefixed(): void
    {
        $this->limitTo(200);

        $this->forAll(
            Generators::choose(1, 10),
        )->then(static function (int $lineCount): void {
            $lines = [];
            for ($i = 0; $i < $lineCount; ++$i) {
                $lines[] = 'line-' . $i;
            }
            $data = implode("\n", $lines);

            $sseEvent = new SseEvent(data: $data);
            $formatted = $sseEvent->format();

            // Each line of data must be prefixed with "data: "
            $formattedLines = explode("\n", mb_rtrim($formatted, "\n"));
            $dataLines = array_filter($formattedLines, static fn (string $l) => str_starts_with($l, 'data: '));

            self::assertCount(
                $lineCount,
                $dataLines,
                "Expected {$lineCount} data lines for {$lineCount}-line input",
            );

            // Verify each data line content
            $dataValues = array_map(static fn (string $l) => mb_substr($l, 6), array_values($dataLines));
            self::assertSame($lines, $dataValues);
        });
    }

    #[Test]
    public function optionalFieldsOnlyAppearWhenSet(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::string(),
        )->then(static function (string $data): void {
            $data = str_replace("\r", '', $data);

            // Event with no optional fields
            $sseEvent = new SseEvent(data: $data);
            $formatted = $sseEvent->format();

            // Check that optional SSE fields don't appear as line prefixes.
            // The data content itself may contain these strings, so we check
            // line-by-line that no line starts with event:/id:/retry:.
            $lines = explode("\n", mb_rtrim($formatted, "\n"));
            foreach ($lines as $line) {
                self::assertFalse(str_starts_with($line, 'event:'), "No line should start with 'event:' when event is null, got: {$line}");
                self::assertFalse(str_starts_with($line, 'id:'), "No line should start with 'id:' when id is null, got: {$line}");
                self::assertFalse(str_starts_with($line, 'retry:'), "No line should start with 'retry:' when retry is null, got: {$line}");
            }
        });
    }

    #[Test]
    public function eventFieldAppearsBeforeData(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::string(),
            Generators::elements(['message', 'update', 'ping', 'custom-event']),
        )->then(static function (string $data, string $eventName): void {
            $data = str_replace("\r", '', $data);

            $sseEvent = new SseEvent(data: $data, event: $eventName);
            $formatted = $sseEvent->format();

            $eventPos = mb_strpos($formatted, 'event: ');
            $dataPos = mb_strpos($formatted, 'data: ');

            self::assertNotFalse($eventPos);
            self::assertNotFalse($dataPos);
            self::assertLessThan($dataPos, $eventPos, 'event: must appear before data:');
        });
    }

    #[Test]
    public function retryFieldIsNumeric(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(0, 60000),
        )->then(static function (int $retryMs): void {
            $sseEvent = new SseEvent(data: 'test', retry: $retryMs);
            $formatted = $sseEvent->format();

            self::assertMatchesRegularExpression(
                '/retry: \d+\n/',
                $formatted,
                'retry field must contain only digits',
            );

            // Verify the value matches
            preg_match('/retry: (\d+)/', $formatted, $matches);
            self::assertSame($retryMs, (int) $matches[1]);
        });
    }
}
