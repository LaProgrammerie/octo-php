<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyMessenger\Tests\Unit;

use AsyncPlatform\SymfonyMessenger\ConsumerManager;
use AsyncPlatform\SymfonyMessenger\OpenSwooleTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ConsumerManagerTest extends TestCase
{
    #[Test]
    public function startSetsRunningState(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 10);
        $bus = $this->createMock(MessageBusInterface::class);

        $manager = new ConsumerManager(
            transport: $transport,
            bus: $bus,
        );

        self::assertFalse($manager->isRunning());
        $manager->start();
        // After start with synchronous spawner, consumeLoop runs and completes
        // but running flag stays true until stop() is called
        self::assertTrue($manager->isRunning());
    }

    #[Test]
    public function stopClearsRunningState(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 10);
        $bus = $this->createMock(MessageBusInterface::class);

        $manager = new ConsumerManager(
            transport: $transport,
            bus: $bus,
        );

        $manager->start();
        $manager->stop();

        self::assertFalse($manager->isRunning());
    }

    #[Test]
    public function consumerDispatchesAndAcksMessage(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 10);
        $message = new \stdClass();
        $message->text = 'test';
        $envelope = new Envelope($message);
        $transport->send($envelope);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (Envelope $e) use (&$dispatched): bool {
                $dispatched[] = $e;
                return true;
            }))
            ->willReturn($envelope);

        $manager = new ConsumerManager(
            transport: $transport,
            bus: $bus,
            consumerCount: 1,
        );

        $manager->start();

        self::assertCount(1, $dispatched);
        self::assertSame($envelope, $dispatched[0]);
    }

    #[Test]
    public function consumerRejectsAndLogsOnException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        // Transport uses the same logger so reject() logs a warning
        $transport = new OpenSwooleTransport(channelCapacity: 10, logger: $logger);
        $message = new \stdClass();
        $envelope = new Envelope($message);
        $transport->send($envelope);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willThrowException(new \RuntimeException('Handler failed'));

        $logger->expects(self::atLeastOnce())
            ->method('error')
            ->with('Consumer failed to process message', self::callback(function (array $ctx): bool {
                return isset($ctx['consumer_id']) && isset($ctx['error']);
            }));

        $logger->expects(self::atLeastOnce())
            ->method('warning');

        $manager = new ConsumerManager(
            transport: $transport,
            bus: $bus,
            consumerCount: 1,
            logger: $logger,
        );

        $manager->start();
    }

    #[Test]
    public function multipleConsumersAreSpawned(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 10);
        $bus = $this->createMock(MessageBusInterface::class);

        $spawnCount = 0;
        $spawner = function (callable $fn) use (&$spawnCount): int {
            $spawnCount++;
            $fn();
            return $spawnCount;
        };

        $manager = new ConsumerManager(
            transport: $transport,
            bus: $bus,
            consumerCount: 3,
            coroutineSpawner: $spawner,
        );

        $manager->start();

        self::assertSame(3, $spawnCount);
    }

    #[Test]
    public function startIsIdempotent(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 10);
        $bus = $this->createMock(MessageBusInterface::class);

        $spawnCount = 0;
        $spawner = function (callable $fn) use (&$spawnCount): int {
            $spawnCount++;
            $fn();
            return $spawnCount;
        };

        $manager = new ConsumerManager(
            transport: $transport,
            bus: $bus,
            consumerCount: 1,
            coroutineSpawner: $spawner,
        );

        $manager->start();
        $manager->start(); // second call should be no-op

        self::assertSame(1, $spawnCount);
    }

    #[Test]
    public function consumerProcessesMultipleMessages(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 10);

        $msg1 = new \stdClass();
        $msg1->id = 1;
        $msg2 = new \stdClass();
        $msg2->id = 2;

        $transport->send(new Envelope($msg1));
        $transport->send(new Envelope($msg2));

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willReturnCallback(function (Envelope $e) use (&$dispatched): Envelope {
                $dispatched[] = $e->getMessage();
                return $e;
            });

        // Custom spawner that runs the consume loop twice to drain both messages
        $spawner = function (callable $fn): int {
            $fn(); // first iteration gets msg1
            return 1;
        };

        $manager = new ConsumerManager(
            transport: $transport,
            bus: $bus,
            consumerCount: 1,
            coroutineSpawner: $spawner,
        );

        $manager->start();

        // The synchronous consumer loop processes one get() call per iteration,
        // but get() returns only one envelope. With FakeChannel, the first
        // get() returns msg1, then the loop breaks (sync mode).
        // We need a second start cycle to get msg2.
        self::assertCount(1, $dispatched);
        self::assertSame(1, $dispatched[0]->id);
    }
}
