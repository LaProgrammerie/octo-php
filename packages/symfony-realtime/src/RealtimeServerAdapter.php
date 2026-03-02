<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyRealtime;

use Psr\Log\LoggerInterface;

/**
 * Callable compatible with ServerBootstrap::run($handler).
 *
 * Routes HTTP requests to HttpKernelAdapter and WebSocket upgrades
 * to the registered WebSocketHandler.
 *
 * Detection of WebSocket upgrade via headers (case-insensitive):
 * - Upgrade: websocket
 * - Connection: Upgrade (may contain other tokens, e.g. "keep-alive, Upgrade")
 *
 * The $httpAdapter is typed as a callable (not HttpKernelAdapter directly)
 * because HttpKernelAdapter is final. In practice, pass the HttpKernelAdapter
 * instance — it implements __invoke().
 */
final class RealtimeServerAdapter
{
    private RealtimeMetrics $metrics;

    /** @var callable(object, object): void */
    private $httpAdapter;

    /**
     * @param callable(object, object): void $httpAdapter  Typically HttpKernelAdapter
     */
    public function __construct(
        callable $httpAdapter,
        private readonly ?WebSocketHandler $wsHandler,
        private readonly LoggerInterface $logger,
        private readonly int $wsMaxLifetimeSeconds = 3600,
        ?RealtimeMetrics $metrics = null,
    ) {
        $this->httpAdapter = $httpAdapter;
        $this->metrics = $metrics ?? new RealtimeMetrics();
    }

    /**
     * Entry point called by the runtime pack for each request.
     */
    public function __invoke(object $request, object $response): void
    {
        if ($this->wsHandler !== null && $this->isWebSocketUpgrade($request)) {
            $this->handleWebSocket($request, $response);
        } else {
            ($this->httpAdapter)($request, $response);
        }
    }

    /**
     * Detects WebSocket upgrade requests via headers (case-insensitive).
     *
     * Checks:
     * - Upgrade header contains "websocket" (case-insensitive)
     * - Connection header contains "upgrade" token (case-insensitive)
     */
    public function isWebSocketUpgrade(object $request): bool
    {
        $headers = $request->header ?? [];

        $upgrade = '';
        $connection = '';

        // Case-insensitive header lookup
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if ($lower === 'upgrade') {
                $upgrade = strtolower((string) $value);
            }
            if ($lower === 'connection') {
                $connection = strtolower((string) $value);
            }
        }

        return $upgrade === 'websocket' && str_contains($connection, 'upgrade');
    }

    /**
     * Handles a WebSocket connection: creates context, delegates to handler.
     */
    private function handleWebSocket(object $request, object $response): void
    {
        $headers = $request->header ?? [];
        $server = $request->server ?? [];
        $connectionId = $request->fd ?? 0;
        $requestId = $headers['x-request-id'] ?? bin2hex(random_bytes(8));

        $ctx = new WebSocketContext(
            connectionId: (int) $connectionId,
            requestId: $requestId,
            headers: $headers,
            sendFn: function (string $data) use ($response): void {
                $this->metrics->incrementMessagesSent();
                if (method_exists($response, 'push')) {
                    $response->push($data);
                }
            },
            closeFn: function () use ($response): void {
                $this->metrics->decrementActiveConnections();
                if (method_exists($response, 'close')) {
                    $response->close();
                }
            },
        );

        $this->metrics->incrementActiveConnections();

        try {
            $this->wsHandler->onOpen($ctx);
        } catch (\Throwable $e) {
            $this->logger->error('WebSocket onOpen failed', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'component' => 'symfony_realtime',
            ]);
            $this->metrics->decrementActiveConnections();
        }
    }

    public function getMetrics(): RealtimeMetrics
    {
        return $this->metrics;
    }

    public function getWsMaxLifetimeSeconds(): int
    {
        return $this->wsMaxLifetimeSeconds;
    }
}
