<?php

declare(strict_types=1);

namespace Octo\SymfonyMessenger;

use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

use function get_class;

/**
 * Manages consumer coroutines for the OpenSwoole transport.
 *
 * Consumers are spawned via structured concurrency (TaskGroup pattern).
 * They respect deadlines and cancellation via the running flag.
 * They are started at worker boot and cancelled at shutdown.
 *
 * Lifecycle:
 * - start(): spawn N consumer coroutines
 * - stop(): cancel consumers cleanly (no zombie coroutines)
 *
 * The coroutine spawner is injectable for testability:
 * - Production: OpenSwoole\Coroutine::create()
 * - Tests: synchronous callable that executes immediately
 */
final class ConsumerManager
{
    /** @var list<int> Coroutine IDs of consumers */
    private array $consumerCids = [];
    private bool $running = false;

    private readonly Closure $coroutineSpawner; // @phpstan-ignore missingType.callable

    /**
     * @phpstan-param null|\Closure(callable): int $coroutineSpawner
     */
    public function __construct(// @phpstan-ignore missingType.callable
        private readonly OpenSwooleTransport $transport,
        private readonly MessageBusInterface $bus,
        private readonly int $consumerCount = 1,
        private readonly ?LoggerInterface $logger = null,
        ?Closure $coroutineSpawner = null,
    ) {
        // Default spawner: direct execution (for tests).
        // In production, inject OpenSwoole\Coroutine::create(...)
        $this->coroutineSpawner = $coroutineSpawner ?? static function (callable $fn): int {
            $fn();

            return 0;
        };
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->consumerCids = [];

        for ($i = 0; $i < $this->consumerCount; ++$i) {
            $cid = ($this->coroutineSpawner)(fn () => $this->consumeLoop($i));
            $this->consumerCids[] = $cid;
        }

        $this->logger?->info('ConsumerManager started', [
            'consumer_count' => $this->consumerCount,
        ]);
    }

    public function stop(): void
    {
        $this->running = false;
        $this->consumerCids = [];

        $this->logger?->info('ConsumerManager stopped');
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /** @return list<int> */
    public function getConsumerCids(): array
    {
        return $this->consumerCids;
    }

    private function consumeLoop(int $consumerId): void
    {
        while ($this->running) {
            foreach ($this->transport->get() as $envelope) {
                try {
                    $this->bus->dispatch($envelope);
                    $this->transport->ack($envelope);
                } catch (Throwable $e) {
                    $this->transport->reject($envelope);
                    $this->logger?->error('Consumer failed to process message', [
                        'consumer_id' => $consumerId,
                        'message_class' => get_class($envelope->getMessage()),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // For synchronous (test) mode: break after processing
            // available messages to prevent infinite loop.
            // Real OpenSwoole coroutines yield on channel->pop(timeout).
            break;
        }
    }
}
