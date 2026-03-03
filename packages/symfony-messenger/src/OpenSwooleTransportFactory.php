<?php

declare(strict_types=1);

namespace Octo\SymfonyMessenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Factory for creating OpenSwooleTransport instances.
 *
 * Supports DSN: openswoole://default
 *
 * Options:
 * - channel_capacity (int, default 100): bounded channel size
 * - send_timeout (float, default 5.0): timeout in seconds for send()
 *
 * @implements TransportFactoryInterface<OpenSwooleTransport>
 */
final class OpenSwooleTransportFactory implements TransportFactoryInterface
{
    private const DSN_SCHEME = 'openswoole://';

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?MessengerMetrics $metrics = null,
    ) {
    }

    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $channelCapacity = (int) ($options['channel_capacity'] ?? 100);
        $sendTimeout = (float) ($options['send_timeout'] ?? 5.0);

        return new OpenSwooleTransport(
            channelCapacity: $channelCapacity,
            sendTimeout: $sendTimeout,
            logger: $this->logger,
            metrics: $this->metrics,
        );
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, self::DSN_SCHEME);
    }
}
