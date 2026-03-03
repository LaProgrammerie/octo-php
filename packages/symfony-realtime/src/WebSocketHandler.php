<?php

declare(strict_types=1);

namespace Octo\SymfonyRealtime;

/**
 * Interface for handling WebSocket connections.
 *
 * Implemented by the application to define WS logic.
 * Frames are passed directly (no HttpFoundation conversion).
 */
interface WebSocketHandler
{
    public function onOpen(WebSocketContext $ctx): void;

    public function onMessage(WebSocketContext $ctx, string $data): void;

    public function onClose(WebSocketContext $ctx): void;
}
