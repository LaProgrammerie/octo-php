<?php

declare(strict_types=1);

namespace Octo\SymfonyMessenger\Tests\Unit;

use Octo\SymfonyMessenger\OpenSwooleTransport;
use Octo\SymfonyMessenger\OpenSwooleTransportFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class OpenSwooleTransportFactoryTest extends TestCase
{
    #[Test]
    public function supportsOpenSwooleDsn(): void
    {
        $factory = new OpenSwooleTransportFactory();

        self::assertTrue($factory->supports('openswoole://default', []));
        self::assertTrue($factory->supports('openswoole://localhost', []));
        self::assertTrue($factory->supports('openswoole://custom-host', []));
    }

    #[Test]
    public function doesNotSupportOtherDsn(): void
    {
        $factory = new OpenSwooleTransportFactory();

        self::assertFalse($factory->supports('amqp://localhost', []));
        self::assertFalse($factory->supports('redis://localhost', []));
        self::assertFalse($factory->supports('doctrine://default', []));
        self::assertFalse($factory->supports('sync://', []));
        self::assertFalse($factory->supports('', []));
    }

    #[Test]
    public function createsTransportWithDefaultOptions(): void
    {
        $factory = new OpenSwooleTransportFactory();
        $serializer = $this->createMock(SerializerInterface::class);

        $transport = $factory->createTransport('openswoole://default', [], $serializer);

        self::assertInstanceOf(OpenSwooleTransport::class, $transport);
        self::assertSame(100, $transport->getChannelCapacity());
    }

    #[Test]
    public function createsTransportWithCustomOptions(): void
    {
        $factory = new OpenSwooleTransportFactory();
        $serializer = $this->createMock(SerializerInterface::class);

        $transport = $factory->createTransport('openswoole://default', [
            'channel_capacity' => 50,
            'send_timeout' => 10.0,
        ], $serializer);

        self::assertInstanceOf(OpenSwooleTransport::class, $transport);
        self::assertSame(50, $transport->getChannelCapacity());
    }
}
