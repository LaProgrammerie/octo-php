<?php

declare(strict_types=1);

namespace Octo\SymfonyMessenger\Tests\Property;

use Octo\SymfonyMessenger\OpenSwooleTransport;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;

/**
 * Property 11: Messenger backpressure
 *
 * **Validates: Requirements 11.4**
 *
 * For any OpenSwooleTransport with a channel of capacity N, after N calls
 * to send() without any get(), the (N+1)th call to send() SHALL block
 * the coroutine (yield) until space is available or the timeout is reached,
 * at which point a TransportException is thrown.
 *
 * With FakeChannel (synchronous), push() returns false immediately when full,
 * which triggers the TransportException without actual coroutine blocking.
 * This validates the backpressure contract at the transport level.
 */
final class MessengerBackpressureTest extends TestCase
{
    use TestTrait;

    #[Test]
    public function sendThrowsWhenChannelFullAfterNMessages(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::choose(1, 50),
        )->then(function (int $capacity): void {
            $transport = new OpenSwooleTransport(
                channelCapacity: $capacity,
                sendTimeout: 0.01,
            );

            // Fill the channel to capacity
            for ($i = 0; $i < $capacity; $i++) {
                $msg = new \stdClass();
                $msg->index = $i;
                $transport->send(new Envelope($msg));
            }

            // Verify channel is at capacity
            self::assertSame($capacity, $transport->getChannelSize());

            // The (N+1)th send MUST throw TransportException
            $thrown = false;
            try {
                $transport->send(new Envelope(new \stdClass()));
            } catch (TransportException $e) {
                $thrown = true;
                self::assertStringContainsString(
                    "capacity: {$capacity}",
                    $e->getMessage(),
                    'Exception message should include the channel capacity',
                );
            }

            self::assertTrue($thrown, "TransportException must be thrown when channel is full (capacity={$capacity})");

            // Channel size should still be N (the failed send didn't add anything)
            self::assertSame($capacity, $transport->getChannelSize());
        });
    }

    #[Test]
    public function sendSucceedsAfterGetFreesSpace(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::choose(1, 30),
        )->then(function (int $capacity): void {
            $transport = new OpenSwooleTransport(
                channelCapacity: $capacity,
                sendTimeout: 0.01,
            );

            // Fill the channel
            for ($i = 0; $i < $capacity; $i++) {
                $transport->send(new Envelope(new \stdClass()));
            }

            // Consume one message to free space
            $received = iterator_to_array($transport->get());
            self::assertCount(1, $received);
            self::assertSame($capacity - 1, $transport->getChannelSize());

            // Now send should succeed
            $msg = new \stdClass();
            $msg->afterFree = true;
            $envelope = new Envelope($msg);
            $result = $transport->send($envelope);

            self::assertSame($envelope, $result);
            self::assertSame($capacity, $transport->getChannelSize());
        });
    }

    #[Test]
    public function channelCapacityIsRespectedForAnyN(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::choose(1, 100),
        )->then(function (int $capacity): void {
            $transport = new OpenSwooleTransport(
                channelCapacity: $capacity,
                sendTimeout: 0.01,
            );

            self::assertSame($capacity, $transport->getChannelCapacity());

            // Send exactly N messages — all should succeed
            for ($i = 0; $i < $capacity; $i++) {
                $transport->send(new Envelope(new \stdClass()));
            }

            self::assertSame($capacity, $transport->getChannelSize());

            // N+1 should fail
            $exceptionThrown = false;
            try {
                $transport->send(new Envelope(new \stdClass()));
            } catch (TransportException) {
                $exceptionThrown = true;
            }

            self::assertTrue($exceptionThrown);
        });
    }
}
