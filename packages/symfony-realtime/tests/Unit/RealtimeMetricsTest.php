<?php

declare(strict_types=1);

namespace Octo\SymfonyRealtime\Tests\Unit;

use Octo\SymfonyRealtime\RealtimeMetrics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RealtimeMetricsTest extends TestCase
{
    #[Test]
    public function startsAtZero(): void
    {
        $metrics = new RealtimeMetrics();

        self::assertSame(0, $metrics->getConnectionsActive());
        self::assertSame(0, $metrics->getMessagesReceivedTotal());
        self::assertSame(0, $metrics->getMessagesSentTotal());
    }

    #[Test]
    public function tracksActiveConnections(): void
    {
        $metrics = new RealtimeMetrics();

        $metrics->incrementActiveConnections();
        $metrics->incrementActiveConnections();
        self::assertSame(2, $metrics->getConnectionsActive());

        $metrics->decrementActiveConnections();
        self::assertSame(1, $metrics->getConnectionsActive());
    }

    #[Test]
    public function activeConnectionsNeverGoNegative(): void
    {
        $metrics = new RealtimeMetrics();

        $metrics->decrementActiveConnections();
        self::assertSame(0, $metrics->getConnectionsActive());
    }

    #[Test]
    public function tracksMessagesReceived(): void
    {
        $metrics = new RealtimeMetrics();

        $metrics->incrementMessagesReceived();
        $metrics->incrementMessagesReceived();
        $metrics->incrementMessagesReceived();

        self::assertSame(3, $metrics->getMessagesReceivedTotal());
    }

    #[Test]
    public function tracksMessagesSent(): void
    {
        $metrics = new RealtimeMetrics();

        $metrics->incrementMessagesSent();
        $metrics->incrementMessagesSent();

        self::assertSame(2, $metrics->getMessagesSentTotal());
    }

    #[Test]
    public function snapshotReturnsAllMetrics(): void
    {
        $metrics = new RealtimeMetrics();

        $metrics->incrementActiveConnections();
        $metrics->incrementMessagesReceived();
        $metrics->incrementMessagesReceived();
        $metrics->incrementMessagesSent();

        self::assertSame([
            'ws_connections_active' => 1,
            'ws_messages_received_total' => 2,
            'ws_messages_sent_total' => 1,
        ], $metrics->snapshot());
    }
}
