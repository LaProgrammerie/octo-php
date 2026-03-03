<?php

declare(strict_types=1);

namespace Octo\SymfonyMessenger;

use Octo\SymfonyBridge\MetricsBridge;

/**
 * Messenger-specific metrics bridge.
 *
 * Tracks:
 * - messenger_messages_sent_total (counter)
 * - messenger_messages_consumed_total (counter)
 * - messenger_channel_size (gauge)
 *
 * Wraps the core MetricsBridge pattern from symfony-bridge,
 * maintaining local counters for snapshot access.
 */
final class MessengerMetrics
{
    private int $sentTotal = 0;
    private int $consumedTotal = 0;
    private int $channelSize = 0;

    public function incrementSent(): void
    {
        $this->sentTotal++;
    }

    public function incrementConsumed(): void
    {
        $this->consumedTotal++;
    }

    public function recordChannelSize(int $size): void
    {
        $this->channelSize = $size;
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        return [
            'messenger_messages_sent_total' => $this->sentTotal,
            'messenger_messages_consumed_total' => $this->consumedTotal,
            'messenger_channel_size' => $this->channelSize,
        ];
    }

    public function getSentTotal(): int
    {
        return $this->sentTotal;
    }

    public function getConsumedTotal(): int
    {
        return $this->consumedTotal;
    }

    public function getChannelSize(): int
    {
        return $this->channelSize;
    }
}
