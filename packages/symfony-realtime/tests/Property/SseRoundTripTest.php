<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyRealtime\Tests\Property;

use AsyncPlatform\SymfonyRealtime\SseEvent;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Property 14: SSE format/parse round-trip
 *
 * **Validates: Requirements 13.4**
 *
 * For any SseEvent, format() then parse() must restore the original fields:
 * - data (including multi-line)
 * - event (nullable)
 * - id (nullable)
 * - retry (nullable)
 */
final class SseRoundTripTest extends TestCase
{
    use TestTrait;

    #[Test]
    public function roundTripPreservesDataOnly(): void
    {
        $this->limitTo(200);

        $this->forAll(
            Generators::string(),
        )->then(function (string $data): void {
            // Filter \r to avoid cross-platform issues in SSE parsing
            $data = str_replace("\r", '', $data);

            $original = new SseEvent(data: $data);
            $parsed = SseEvent::parse($original->format());

            self::assertSame($original->data, $parsed->data);
            self::assertNull($parsed->event);
            self::assertNull($parsed->id);
            self::assertNull($parsed->retry);
        });
    }

    #[Test]
    public function roundTripPreservesAllFields(): void
    {
        $this->limitTo(200);

        $this->forAll(
            Generators::string(),
            Generators::elements(['message', 'update', 'ping', 'custom']),
            Generators::elements(['1', '42', 'evt-abc', 'id-xyz']),
            Generators::choose(0, 60000),
        )->then(function (string $data, string $event, string $id, int $retry): void {
            $data = str_replace("\r", '', $data);

            $original = new SseEvent(
                data: $data,
                event: $event,
                id: $id,
                retry: $retry,
            );

            $parsed = SseEvent::parse($original->format());

            self::assertSame($original->data, $parsed->data, 'data mismatch');
            self::assertSame($original->event, $parsed->event, 'event mismatch');
            self::assertSame($original->id, $parsed->id, 'id mismatch');
            self::assertSame($original->retry, $parsed->retry, 'retry mismatch');
        });
    }

    #[Test]
    public function roundTripPreservesMultiLineData(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(1, 20),
        )->then(function (int $lineCount): void {
            $lines = [];
            for ($i = 0; $i < $lineCount; $i++) {
                $lines[] = 'content-line-' . $i . '-' . bin2hex(random_bytes(4));
            }
            $data = implode("\n", $lines);

            $original = new SseEvent(
                data: $data,
                event: 'multi-line-test',
                id: 'ml-' . $lineCount,
            );

            $parsed = SseEvent::parse($original->format());

            self::assertSame($original->data, $parsed->data, 'Multi-line data mismatch');
            self::assertSame($original->event, $parsed->event);
            self::assertSame($original->id, $parsed->id);
        });
    }

    #[Test]
    public function roundTripWithOptionalFieldCombinations(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::elements([null, 'evt-type']),
            Generators::elements([null, 'id-123']),
            Generators::elements([null, 1000]),
        )->then(function (?string $event, ?string $id, ?int $retry): void {
            $original = new SseEvent(
                data: 'test-payload',
                event: $event,
                id: $id,
                retry: $retry,
            );

            $parsed = SseEvent::parse($original->format());

            self::assertSame($original->data, $parsed->data);
            self::assertSame($original->event, $parsed->event);
            self::assertSame($original->id, $parsed->id);
            self::assertSame($original->retry, $parsed->retry);
        });
    }
}
