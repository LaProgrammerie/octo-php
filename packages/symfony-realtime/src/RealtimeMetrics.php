<?php

declare(strict_types=1);

namespace Octo\SymfonyRealtime;

/**
 * WebSocket-specific metrics.
 *
 * Tracks:
 * - ws_connections_active (gauge): currently open WS connections
 * - ws_messages_received_total (counter): total messages received from clients
 * - ws_messages_sent_total (counter): total messages sent to clients
 */
final class RealtimeMetrics
{
    private int $connectionsActive = 0;
    private int $messagesReceivedTotal = 0;
    private int $messagesSentTotal = 0;

    public function incrementActiveConnections(): void
    {
        $this->connectionsActive++;
    }

    public function decrementActiveConnections(): void
    {
        $this->connectionsActive = max(0, $this->connectionsActive - 1);
    }

    public function incrementMessagesReceived(): void
    {
        $this->messagesReceivedTotal++;
    }

    public function incrementMessagesSent(): void
    {
        $this->messagesSentTotal++;
    }

    public function getConnectionsActive(): int
    {
        return $this->connectionsActive;
    }

    public function getMessagesReceivedTotal(): int
    {
        return $this->messagesReceivedTotal;
    }

    public function getMessagesSentTotal(): int
    {
        return $this->messagesSentTotal;
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        return [
            'ws_connections_active' => $this->connectionsActive,
            'ws_messages_received_total' => $this->messagesReceivedTotal,
            'ws_messages_sent_total' => $this->messagesSentTotal,
        ];
    }
}
