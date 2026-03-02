<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge\Tests\Integration;

require_once __DIR__ . '/IntegrationTestDoubles.php';

use AsyncPlatform\SymfonyMessenger\ConsumerManager;
use AsyncPlatform\SymfonyMessenger\FakeChannel;
use AsyncPlatform\SymfonyMessenger\MessengerMetrics;
use AsyncPlatform\SymfonyMessenger\OpenSwooleTransport;
use AsyncPlatform\SymfonyMessenger\OpenSwooleTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Integration test: Messenger transport end-to-end.
 *
 * Verifies:
 * - send/get/ack/reject end-to-end
 * - Backpressure with full channel
 * - Consumer lifecycle (start/stop)
 *
 * Requirements: 16.14
 */
final class MessengerIntegrationTest extends TestCase
{
    public function testSendGetAckRejectEndToEnd(): void
    {
        $metrics = new MessengerMetrics();
        $transport = new OpenSwooleTransport(
            channelCapacity: 10,
            sendTimeout: 1.0,
            logger: new IntegrationSpyLogger(),
            metrics: $metrics,
        );

        // Send
        $message = new \stdClass();
        $message->text = 'Hello Messenger';
        $envelope = new Envelope($message);

        $sent = $transport->send($envelope);
        $this->assertInstanceOf(Envelope::class, $sent);
        $this->assertSame(1, $metrics->getSentTotal());

        // Get
        $received = iterator_to_array($transport->get());
        $this->assertCount(1, $received);
        $this->assertInstanceOf(Envelope::class, $received[0]);
        $this->assertSame('Hello Messenger', $received[0]->getMessage()->text);
        $this->assertSame(1, $metrics->getConsumedTotal());

        // Ack (no-op for in-process)
        $transport->ack($received[0]);

        // Reject (logs warning)
        $logger = new IntegrationSpyLogger();
        $transportWithLogger = new OpenSwooleTransport(
            channelCapacity: 10,
            sendTimeout: 1.0,
            logger: $logger,
            metrics: new MessengerMetrics(),
        );
        $transportWithLogger->send($envelope);
        $msgs = iterator_to_array($transportWithLogger->get());
        $transportWithLogger->reject($msgs[0]);
        $this->assertTrue($logger->hasLogMatching('warning', 'rejected'));
    }

    public function testBackpressureWithFullChannel(): void
    {
        $transport = new OpenSwooleTransport(
            channelCapacity: 3,
            sendTimeout: 0.01, // very short timeout
        );

        // Fill the channel
        for ($i = 0; $i < 3; $i++) {
            $msg = new \stdClass();
            $msg->id = $i;
            $transport->send(new Envelope($msg));
        }

        $this->assertSame(3, $transport->getChannelSize());

        // Next send should throw TransportException (channel full + timeout)
        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/channel full/i');

        $overflow = new \stdClass();
        $overflow->id = 'overflow';
        $transport->send(new Envelope($overflow));
    }

    public function testFifoOrdering(): void
    {
        $transport = new OpenSwooleTransport(channelCapacity: 10);

        $messages = [];
        for ($i = 0; $i < 5; $i++) {
            $msg = new \stdClass();
            $msg->order = $i;
            $messages[] = $msg;
            $transport->send(new Envelope($msg));
        }

        // Get them back — should be FIFO
        for ($i = 0; $i < 5; $i++) {
            $received = iterator_to_array($transport->get());
            $this->assertCount(1, $received);
            $this->assertSame($i, $received[0]->getMessage()->order);
        }
    }

    public function testConsumerManagerStartStop(): void
    {
        $dispatched = [];

        $bus = new class ($dispatched) implements MessageBusInterface {
            private array $dispatched;
            public function __construct(array &$dispatched)
            {
                $this->dispatched = &$dispatched; }
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = $message instanceof Envelope ? $message : new Envelope($message);
                $this->dispatched[] = $envelope;
                return $envelope;
            }
        };

        $transport = new OpenSwooleTransport(channelCapacity: 10);
        $logger = new IntegrationSpyLogger();

        $consumer = new ConsumerManager(
            transport: $transport,
            bus: $bus,
            consumerCount: 1,
            logger: $logger,
        );

        // Send a message before starting consumer
        $msg = new \stdClass();
        $msg->text = 'consumed';
        $transport->send(new Envelope($msg));

        // Start consumer — in synchronous test mode, it processes immediately
        $consumer->start();
        $this->assertTrue($consumer->isRunning());

        // Message should have been dispatched
        $this->assertCount(1, $dispatched);
        $this->assertSame('consumed', $dispatched[0]->getMessage()->text);

        // Stop
        $consumer->stop();
        $this->assertFalse($consumer->isRunning());
    }

    public function testConsumerManagerHandlesExceptionInDispatch(): void
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('Dispatch failed');
            }
        };

        $transport = new OpenSwooleTransport(channelCapacity: 10);
        $logger = new IntegrationSpyLogger();

        $msg = new \stdClass();
        $msg->text = 'will-fail';
        $transport->send(new Envelope($msg));

        $consumer = new ConsumerManager(
            transport: $transport,
            bus: $bus,
            consumerCount: 1,
            logger: $logger,
        );

        $consumer->start();

        // Error should be logged, consumer should not crash
        $this->assertTrue($logger->hasLogMatching('error', 'Consumer failed to process message'));

        $consumer->stop();
    }

    public function testTransportFactorySupportsOpenSwooleDsn(): void
    {
        $factory = new OpenSwooleTransportFactory();

        $this->assertTrue($factory->supports('openswoole://default', []));
        $this->assertFalse($factory->supports('amqp://localhost', []));
    }

    public function testMetricsTrackedAcrossSendGet(): void
    {
        $metrics = new MessengerMetrics();
        $transport = new OpenSwooleTransport(
            channelCapacity: 10,
            metrics: $metrics,
        );

        for ($i = 0; $i < 5; $i++) {
            $msg = new \stdClass();
            $msg->id = $i;
            $transport->send(new Envelope($msg));
        }

        $this->assertSame(5, $metrics->getSentTotal());

        for ($i = 0; $i < 3; $i++) {
            iterator_to_array($transport->get());
        }

        $this->assertSame(3, $metrics->getConsumedTotal());

        $snapshot = $metrics->snapshot();
        $this->assertSame(5, $snapshot['messenger_messages_sent_total']);
        $this->assertSame(3, $snapshot['messenger_messages_consumed_total']);
    }
}
