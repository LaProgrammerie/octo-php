<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyMessenger\Tests\Unit;

use AsyncPlatform\SymfonyMessenger\MessengerMetrics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MessengerMetricsTest extends TestCase
{
    #[Test]
    public function initialStateIsZero(): void
    {
        $metrics = new MessengerMetrics();

        self::assertSame(0, $metrics->getSentTotal());
        self::assertSame(0, $metrics->getConsumedTotal());
        self::assertSame(0, $metrics->getChannelSize());
    }

    #[Test]
    public function incrementSentCountsCorrectly(): void
    {
        $metrics = new MessengerMetrics();

        $metrics->incrementSent();
        $metrics->incrementSent();
        $metrics->incrementSent();

        self::assertSame(3, $metrics->getSentTotal());
    }

    #[Test]
    public function incrementConsumedCountsCorrectly(): void
    {
        $metrics = new MessengerMetrics();

        $metrics->incrementConsumed();
        $metrics->incrementConsumed();

        self::assertSame(2, $metrics->getConsumedTotal());
    }

    #[Test]
    public function recordChannelSizeUpdatesGauge(): void
    {
        $metrics = new MessengerMetrics();

        $metrics->recordChannelSize(5);
        self::assertSame(5, $metrics->getChannelSize());

        $metrics->recordChannelSize(3);
        self::assertSame(3, $metrics->getChannelSize());
    }

    #[Test]
    public function snapshotReturnsAllMetrics(): void
    {
        $metrics = new MessengerMetrics();

        $metrics->incrementSent();
        $metrics->incrementSent();
        $metrics->incrementConsumed();
        $metrics->recordChannelSize(1);

        $snapshot = $metrics->snapshot();

        self::assertSame([
            'messenger_messages_sent_total' => 2,
            'messenger_messages_consumed_total' => 1,
            'messenger_channel_size' => 1,
        ], $snapshot);
    }
}
