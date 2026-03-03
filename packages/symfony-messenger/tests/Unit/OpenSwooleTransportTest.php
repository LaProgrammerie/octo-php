<?php

declare(strict_types=1);

namespace Octo\SymfonyMessenger\Tests\Unit;

use Octo\SymfonyMessenger\FakeChannel;
use Octo\SymfonyMessenger\MessengerMetrics;
use Octo\SymfonyMessenger\OpenSwooleTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;

final class OpenSwooleTransportTest extends TestCase
{
    #[Test]
    public function sendPushesEnvelopeToChannelAndReturnsIt(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 10);
        $message = new \stdClass();
        $message->text = 'hello';
        $envelope = new Envelope($message);

        $result = $transport->send($envelope);

        self::assertSame($envelope, $result);
        self::assertSame(1, $transport->getChannelSize());
    }

    #[Test]
    public function getReturnsEnvelopeFromChannel(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 10);
        $message = new \stdClass();
        $message->text = 'hello';
        $envelope = new Envelope($message);

        $transport->send($envelope);
        $received = iterator_to_array($transport->get());

        self::assertCount(1, $received);
        self::assertSame($envelope, $received[0]);
        self::assertSame(0, $transport->getChannelSize());
    }

    #[Test]
    public function getReturnsEmptyWhenChannelIsEmpty(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 10);

        $received = iterator_to_array($transport->get());

        self::assertCount(0, $received);
    }

    #[Test]
    public function ackIsNoOp(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 10);
        $envelope = new Envelope(new \stdClass());

        // Should not throw
        $transport->ack($envelope);
        self::assertTrue(true);
    }

    #[Test]
    public function rejectLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('Message rejected', self::callback(function (array $context): bool {
                return isset($context['message_class']);
            }));

        $transport = new OpenSwooleTransport(
            channelCapacity: 10,
            logger: $logger,
        );
        $envelope = new Envelope(new \stdClass());

        $transport->reject($envelope);
    }

    #[Test]
    public function sendThrowsTransportExceptionWhenChannelFull(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 2, sendTimeout: 0.1);

        $transport->send(new Envelope(new \stdClass()));
        $transport->send(new Envelope(new \stdClass()));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/channel full.*capacity: 2/i');

        $transport->send(new Envelope(new \stdClass()));
    }

    #[Test]
    public function backpressureChannelFullBlocksAndTimeout(): void
    {
        $capacity = 3;
        $transport = new OpenSwooleTransport(
            channelCapacity: $capacity,
            sendTimeout: 0.01,
        );

        // Fill the channel
        for ($i = 0; $i < $capacity; $i++) {
            $transport->send(new Envelope(new \stdClass()));
        }

        self::assertSame($capacity, $transport->getChannelSize());

        // Next send should throw
        $thrown = false;
        try {
            $transport->send(new Envelope(new \stdClass()));
        } catch (TransportException) {
            $thrown = true;
        }
        self::assertTrue($thrown, 'TransportException should be thrown when channel is full');
    }

    #[Test]
    public function fifoOrderIsPreserved(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 10);

        $messages = [];
        for ($i = 0; $i < 5; $i++) {
            $msg = new \stdClass();
            $msg->order = $i;
            $envelope = new Envelope($msg);
            $messages[] = $envelope;
            $transport->send($envelope);
        }

        for ($i = 0; $i < 5; $i++) {
            $received = iterator_to_array($transport->get());
            self::assertCount(1, $received);
            self::assertSame($messages[$i], $received[0]);
        }
    }

    #[Test]
    public function metricsAreIncrementedOnSend(): void
    {
        $metrics = new MessengerMetrics();
        $transport = new OpenSwooleTransport(
            channelCapacity: 10,
            metrics: $metrics,
        );

        $transport->send(new Envelope(new \stdClass()));
        $transport->send(new Envelope(new \stdClass()));

        self::assertSame(2, $metrics->getSentTotal());
    }

    #[Test]
    public function metricsAreIncrementedOnGet(): void
    {
        $metrics = new MessengerMetrics();
        $transport = new OpenSwooleTransport(
            channelCapacity: 10,
            metrics: $metrics,
        );

        $transport->send(new Envelope(new \stdClass()));
        iterator_to_array($transport->get());

        self::assertSame(1, $metrics->getConsumedTotal());
    }

    #[Test]
    public function metricsNotIncrementedOnEmptyGet(): void
    {
        $metrics = new MessengerMetrics();
        $transport = new OpenSwooleTransport(
            channelCapacity: 10,
            metrics: $metrics,
        );

        iterator_to_array($transport->get());

        self::assertSame(0, $metrics->getConsumedTotal());
    }

    #[Test]
    public function channelCapacityIsExposed(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 42);
        self::assertSame(42, $transport->getChannelCapacity());
    }

    #[Test]
    public function channelSizeTracksCorrectly(): void
    {
        $metrics = new MessengerMetrics();
        $transport = new OpenSwooleTransport(
            channelCapacity: 10,
            metrics: $metrics,
        );

        $transport->send(new Envelope(new \stdClass()));
        $transport->send(new Envelope(new \stdClass()));
        self::assertSame(2, $metrics->getChannelSize());

        iterator_to_array($transport->get());
        self::assertSame(1, $metrics->getChannelSize());
    }
}
