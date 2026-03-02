<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyMessenger\Tests\Property;

use AsyncPlatform\SymfonyMessenger\OpenSwooleTransport;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

/**
 * Property 10: Messenger send/get round-trip
 *
 * **Validates: Requirements 11.3**
 *
 * For any Envelope sent via OpenSwooleTransport::send(), a subsequent
 * call to get() SHALL return an equivalent Envelope (same message, same stamps).
 * The FIFO order of the channel is preserved.
 */
final class MessengerRoundTripTest extends TestCase
{
    use TestTrait;

    #[Test]
    public function singleEnvelopeRoundTrip(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::string(),
            Generators::choose(0, 1000),
        )->then(function (string $text, int $id): void {
            $transport = new OpenSwooleTransport(channelCapacity: 100);

            $message = new \stdClass();
            $message->text = $text;
            $message->id = $id;
            $envelope = new Envelope($message);

            $sent = $transport->send($envelope);
            $received = iterator_to_array($transport->get());

            self::assertCount(1, $received);
            self::assertSame($sent, $received[0], 'Round-trip should return the same Envelope instance');
            self::assertSame($text, $received[0]->getMessage()->text);
            self::assertSame($id, $received[0]->getMessage()->id);
        });
    }

    #[Test]
    public function fifoOrderPreservedForMultipleEnvelopes(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::choose(2, 20),
        )->then(function (int $count): void {
            $transport = new OpenSwooleTransport(channelCapacity: $count + 10);

            $sent = [];
            for ($i = 0; $i < $count; $i++) {
                $message = new \stdClass();
                $message->order = $i;
                $message->payload = bin2hex(random_bytes(8));
                $envelope = new Envelope($message);
                $sent[] = $transport->send($envelope);
            }

            $received = [];
            for ($i = 0; $i < $count; $i++) {
                $items = iterator_to_array($transport->get());
                self::assertCount(1, $items, "Expected exactly 1 envelope at position {$i}");
                $received[] = $items[0];
            }

            // Verify FIFO order
            for ($i = 0; $i < $count; $i++) {
                self::assertSame(
                    $sent[$i],
                    $received[$i],
                    "Envelope at position {$i} should match sent order (FIFO)",
                );
                self::assertSame(
                    $i,
                    $received[$i]->getMessage()->order,
                    "Message order field at position {$i} should be {$i}",
                );
            }

            // Channel should be empty after draining
            $empty = iterator_to_array($transport->get());
            self::assertCount(0, $empty, 'Channel should be empty after draining all messages');
        });
    }

    #[Test]
    public function messageContentPreservedThroughRoundTrip(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::string(),
            Generators::choose(-1000000, 1000000),
            Generators::elements([true, false]),
        )->then(function (string $text, int $number, bool $flag): void {
            $transport = new OpenSwooleTransport(channelCapacity: 10);

            $message = new \stdClass();
            $message->text = $text;
            $message->number = $number;
            $message->flag = $flag;
            $envelope = new Envelope($message);

            $transport->send($envelope);
            $received = iterator_to_array($transport->get());

            self::assertCount(1, $received);
            $receivedMsg = $received[0]->getMessage();
            self::assertSame($text, $receivedMsg->text);
            self::assertSame($number, $receivedMsg->number);
            self::assertSame($flag, $receivedMsg->flag);
        });
    }
}
