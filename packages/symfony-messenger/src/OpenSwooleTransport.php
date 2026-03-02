<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyMessenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Transport Messenger in-process via bounded channel.
 *
 * DSN: openswoole://default
 *
 * The channel is isolated per worker: each worker owns its own channel.
 * Messages are NOT shared between workers.
 * Messages are lost on worker restart (non-durable).
 *
 * Backpressure: if the channel is full, send() blocks the coroutine
 * until space is available or the timeout is reached.
 *
 * @implements TransportInterface
 */
final class OpenSwooleTransport implements TransportInterface
{
    private ChannelInterface $channel;

    public function __construct(
        int $channelCapacity = 100,
        private readonly float $sendTimeout = 5.0,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?MessengerMetrics $metrics = null,
        ?ChannelInterface $channel = null,
    ) {
        $this->channel = $channel ?? new FakeChannel($channelCapacity);
    }

    public function send(Envelope $envelope): Envelope
    {
        $pushed = $this->channel->push($envelope, $this->sendTimeout);

        if ($pushed === false) {
            throw new TransportException(
                sprintf(
                    'OpenSwoole channel full (capacity: %d). Send timeout after %.1fs.',
                    $this->channel->capacity(),
                    $this->sendTimeout,
                ),
            );
        }

        $this->metrics?->incrementSent();
        $this->metrics?->recordChannelSize($this->channel->length());

        return $envelope;
    }

    /** @return iterable<Envelope> */
    public function get(): iterable
    {
        $envelope = $this->channel->pop(1.0); // 1s poll timeout

        if ($envelope === false) {
            return [];
        }

        $this->metrics?->incrementConsumed();
        $this->metrics?->recordChannelSize($this->channel->length());

        return [$envelope];
    }

    public function ack(Envelope $envelope): void
    {
        // In-process: ack is a no-op (message already consumed from channel)
    }

    public function reject(Envelope $envelope): void
    {
        $this->logger?->warning('Message rejected', [
            'message_class' => get_class($envelope->getMessage()),
        ]);
    }

    /**
     * Returns the current channel size (for metrics/diagnostics).
     */
    public function getChannelSize(): int
    {
        return $this->channel->length();
    }

    /**
     * Returns the channel capacity.
     */
    public function getChannelCapacity(): int
    {
        return $this->channel->capacity();
    }
}
