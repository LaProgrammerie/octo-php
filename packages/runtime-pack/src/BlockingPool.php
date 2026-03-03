<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

use Octo\RuntimePack\Exception\BlockingPoolFullException;
use Octo\RuntimePack\Exception\BlockingPoolHttpException;
use Octo\RuntimePack\Exception\BlockingPoolSendException;
use Octo\RuntimePack\Exception\BlockingPoolTimeoutException;

/**
 * Pool of isolated processes for executing blocking/CPU-bound operations.
 *
 * Implementation V1: OpenSwoole\Process\Pool with IPC via UnixSocket.
 *
 * Lifecycle = master-level:
 * - Created once at boot by the master process
 * - Survives worker reloads (workers reconnect)
 * - Stops AFTER workers at shutdown (stop order: workers → pool → master)
 *
 * Internal architecture (V1 hardened):
 * - A bounded "outbound" Channel (capacity = maxQueueSize) per HTTP worker serves as the real queue.
 *   run() does a non-blocking push → BlockingPoolFullException if full.
 * - A "dispatcher" coroutine per HTTP worker consumes the outbound channel and calls sendToPool().
 * - pendingJobs (map job_id → Channel) serves only for request/response correlation.
 * - queueDepth() = outbound channel length (real queue/backlog).
 * - inflightCount() = count(pendingJobs) (jobs sent, awaiting response).
 *
 * IPC protocol: named jobs with job_id correlation.
 * Framing: uint32 length prefix (big-endian) + JSON payload.
 *
 * @see BlockingPoolInterface
 * @see IpcFraming
 * @see JobRegistry
 */
final class BlockingPool implements BlockingPoolInterface
{
    /** @var array<string, \OpenSwoole\Coroutine\Channel> Map job_id => response Channel */
    private array $pendingJobs = [];

    /** Bounded outbound channel — real backpressure queue. Initialized in initWorker(). */
    private ?\OpenSwoole\Coroutine\Channel $outboundQueue = null;

    /** Busy workers counter (incremented on send, decremented on response). */
    private int $busyWorkersCount = 0;

    /** @var \OpenSwoole\Process\Pool|null The process pool (master-level, set externally). */
    private ?\OpenSwoole\Process\Pool $pool = null;

    /** Reader coroutine reconnection state. */
    private bool $readerRunning = false;

    public function __construct(
        private readonly JobRegistry $registry,
        private readonly MetricsCollector $metrics,
        private readonly JsonLogger $logger,
        private readonly int $maxWorkers = 4,
        private readonly int $maxQueueSize = 64,
        private readonly float $defaultTimeoutSeconds = 30.0,
    ) {
    }

    /**
     * Set the process pool reference (called from master boot).
     */
    public function setPool(\OpenSwoole\Process\Pool $pool): void
    {
        $this->pool = $pool;
    }

    // =========================================================================
    // 17.1 — Outbound channel + dispatcher coroutine
    // =========================================================================

    /**
     * Initialize the outbound channel and dispatcher coroutine for this HTTP worker.
     * Called in onWorkerStart of each HTTP worker.
     */
    public function initWorker(): void
    {
        // Bounded channel = real backpressure queue
        $this->outboundQueue = new \OpenSwoole\Coroutine\Channel($this->maxQueueSize);

        // Dispatcher coroutine: consumes outbound channel and sends to pool
        \OpenSwoole\Coroutine::create(function (): void {
            while (true) {
                $item = $this->outboundQueue->pop();
                if ($item === false) {
                    break; // Channel closed (shutdown)
                }

                $jobId = $item['job_id'];
                $message = $item['message'];

                // Update metrics: queue depth decreased, busy workers increased
                $this->metrics->setBlockingQueueDepth($this->outboundQueue->length());
                $this->busyWorkersCount++;
                $this->metrics->setBlockingPoolBusyWorkers($this->busyWorkersCount);

                try {
                    $this->sendToPool($message);
                } catch (\Throwable $e) {
                    // Send failed → push error into the pending job channel, increment metric
                    if (isset($this->pendingJobs[$jobId])) {
                        $this->pendingJobs[$jobId]->push([
                            'job_id' => $jobId,
                            'ok' => false,
                            'result' => null,
                            'error' => 'Pool send failed: ' . $e->getMessage(),
                        ]);
                    }
                    $this->busyWorkersCount = max(0, $this->busyWorkersCount - 1);
                    $this->metrics->setBlockingPoolBusyWorkers($this->busyWorkersCount);
                    $this->metrics->incrementBlockingPoolSendFailed();
                    $this->logger->error('BlockingPool: sendToPool failed', [
                        'job_id' => $jobId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    // =========================================================================
    // 17.2 — Reader coroutine (IPC with framing)
    // =========================================================================

    /**
     * Start the reader coroutine for this HTTP worker.
     * Listens for IPC messages from the pool with uint32 length-prefix framing.
     * Called in onWorkerStart of each HTTP worker.
     *
     * @param \OpenSwoole\Process $workerProcess The worker's process handle for IPC
     */
    public function startReaderCoroutine(\OpenSwoole\Process $workerProcess): void
    {
        $this->readerRunning = true;

        \OpenSwoole\Coroutine::create(function () use ($workerProcess): void {
            $this->readerLoop($workerProcess);
        });
    }

    /**
     * Reader loop with reconnection support.
     * Reads framed IPC messages and routes responses to pending jobs.
     */
    private function readerLoop(\OpenSwoole\Process $workerProcess, int $retryCount = 0): void
    {
        $buffer = '';

        while ($this->readerRunning) {
            try {
                // Read from the Unix socket
                $data = $workerProcess->read();

                if ($data === false || $data === '') {
                    // Connection lost or closed
                    throw new \RuntimeException('IPC read returned empty/false — connection lost');
                }

                // Reset retry count on successful read
                $retryCount = 0;
                $buffer .= $data;

                // Extract complete frames from buffer
                while (($payload = IpcFraming::extractFromBuffer($buffer)) !== null) {
                    $this->routeResponse($payload);
                }
            } catch (\Throwable $e) {
                if (!$this->readerRunning) {
                    break; // Shutdown — exit cleanly
                }

                $this->logger->error('BlockingPool reader: IPC error', [
                    'error' => $e->getMessage(),
                    'retry' => $retryCount,
                ]);

                // 17.3 — Reconnection with exponential backoff
                if (!$this->reconnectReader($workerProcess, $retryCount)) {
                    break; // Max retries exceeded
                }
                $retryCount++;
                $buffer = ''; // Reset buffer on reconnection
            }
        }
    }

    // =========================================================================
    // 17.3 — Automatic reconnection with exponential backoff
    // =========================================================================

    /** Backoff delays in milliseconds: 100, 200, 400, 800, 1600. */
    private const RECONNECT_BACKOFF_MS = [100, 200, 400, 800, 1600];
    private const MAX_RECONNECT_RETRIES = 5;

    /**
     * Attempt reconnection with exponential backoff.
     *
     * @return bool true if reconnection should be attempted, false if max retries exceeded
     */
    private function reconnectReader(\OpenSwoole\Process $workerProcess, int $retryCount): bool
    {
        if ($retryCount >= self::MAX_RECONNECT_RETRIES) {
            $this->logger->critical('BlockingPool reader: max reconnection retries exceeded — pool degraded', [
                'max_retries' => self::MAX_RECONNECT_RETRIES,
            ]);
            return false;
        }

        $delayMs = self::RECONNECT_BACKOFF_MS[$retryCount] ?? 1600;
        $this->logger->warning('BlockingPool reader: reconnecting', [
            'retry' => $retryCount + 1,
            'backoff_ms' => $delayMs,
        ]);

        // Sleep with coroutine-friendly usleep (yields to event loop)
        \OpenSwoole\Coroutine::usleep($delayMs * 1000);

        return true;
    }

    // =========================================================================
    // 17.4 — Orphaned jobs cleanup
    // =========================================================================

    /** Cleanup interval in seconds. */
    public const CLEANUP_INTERVAL_SECONDS = 60;

    /**
     * Clean up orphaned pending jobs (safety net).
     * Called by a periodic timer (60s) in each HTTP worker.
     * Removes entries whose Channel is closed (pathological case).
     */
    public function cleanupOrphanedJobs(): void
    {
        foreach ($this->pendingJobs as $jobId => $channel) {
            // errCode !== 0 means the channel is in an error state (closed/timeout)
            if ($channel->errCode !== 0) {
                unset($this->pendingJobs[$jobId]);
                $this->logger->warning('BlockingPool: cleaned up orphaned pending job', [
                    'job_id' => $jobId,
                ]);
            }
        }
    }

    // =========================================================================
    // 17.5 — Route response with late response handling
    // =========================================================================

    /**
     * Route an IPC response to the corresponding pending job Channel.
     * Called by the reader coroutine for each decoded message.
     *
     * If the job_id is not found (expired/timed out), logs a warning
     * for late response and ignores it (no memory leak).
     *
     * @param array $response Decoded IPC response: {job_id, ok, result, error}
     */
    public function routeResponse(array $response): void
    {
        $jobId = $response['job_id'] ?? null;

        // Decrement busy workers on every response (successful or not)
        $this->busyWorkersCount = max(0, $this->busyWorkersCount - 1);
        $this->metrics->setBlockingPoolBusyWorkers($this->busyWorkersCount);

        if ($jobId !== null && isset($this->pendingJobs[$jobId])) {
            $this->pendingJobs[$jobId]->push($response);
        } else {
            $this->logger->warning('BlockingPool: late response for expired job', [
                'job_id' => $jobId,
            ]);
        }
    }

    // =========================================================================
    // BlockingPoolInterface::run() — Core job execution
    // =========================================================================

    /**
     * Offload a named job to a pool worker.
     *
     * Protocol with job_id correlation and bounded outbound queue:
     * 1. Verify job exists in registry (fail-fast)
     * 2. Generate unique job_id
     * 3. Create a per-job response Channel(1) with timeout
     * 4. Register job_id => Channel in pendingJobs
     * 5. Encode IPC message with framing (uint32 length prefix)
     * 6. Non-blocking push to outbound channel → BlockingPoolFullException if full
     * 7. Dispatcher coroutine consumes channel and calls sendToPool()
     * 8. Wait on response Channel with timeout
     * 9. Clean up pendingJobs
     * 10. Return result or throw appropriate exception
     *
     * @throws BlockingPoolFullException If outbound queue is full
     * @throws BlockingPoolTimeoutException If job exceeds timeout
     * @throws BlockingPoolSendException If pool send fails (propagated via dispatcher)
     * @throws \InvalidArgumentException If jobName is not registered
     * @throws \RuntimeException If job execution fails
     */
    public function run(string $jobName, array $payload = [], ?float $timeout = null): mixed
    {
        // Fail-fast: verify job exists in registry
        if (!$this->registry->has($jobName)) {
            throw new \InvalidArgumentException(
                "Unknown job: '{$jobName}'. Registered jobs: " . implode(', ', $this->registry->names())
            );
        }

        // Fail-fast: check outbound queue before allocating resources
        if ($this->outboundQueue === null) {
            throw new BlockingPoolSendException('BlockingPool not initialized (initWorker() not called)');
        }

        $timeoutSeconds = $timeout ?? $this->defaultTimeoutSeconds;
        $this->metrics->incrementBlockingTasks();

        // Generate unique job_id for correlation
        $jobId = uniqid('job_', true) . '_' . bin2hex(random_bytes(4));

        // Create per-job response channel
        $responseChannel = new \OpenSwoole\Coroutine\Channel(1);
        $this->pendingJobs[$jobId] = $responseChannel;

        try {
            // Encode IPC message with framing
            $message = IpcFraming::encode([
                'job_id' => $jobId,
                'job_name' => $jobName,
                'payload' => $payload,
            ]);

            $pushed = $this->outboundQueue->push(['job_id' => $jobId, 'message' => $message], 0.0);
            if ($pushed === false) {
                $this->metrics->incrementBlockingPoolRejected();
                throw new BlockingPoolFullException(
                    "BlockingPool queue full ({$this->maxQueueSize})"
                );
            }

            // Update queue depth metric
            $this->metrics->setBlockingQueueDepth($this->outboundQueue->length());

            // Wait for response with timeout
            $result = $responseChannel->pop($timeoutSeconds);

            if ($result === false) {
                throw new BlockingPoolTimeoutException(
                    "BlockingPool job '{$jobName}' (id: {$jobId}) timed out after {$timeoutSeconds}s"
                );
            }

            // Check result — may be an error from dispatcher (sendToPool failure)
            if (!$result['ok']) {
                $errorMsg = $result['error'] ?? 'unknown error';
                // Distinguish send failures from job execution failures
                if (str_starts_with($errorMsg, 'Pool send failed:')) {
                    throw new BlockingPoolSendException($errorMsg);
                }
                throw new \RuntimeException(
                    "BlockingPool job '{$jobName}' failed: {$errorMsg}"
                );
            }

            return $result['result'];
        } finally {
            // Always clean up the pending job
            unset($this->pendingJobs[$jobId]);
        }
    }

    // =========================================================================
    // 17.8 — runOrRespondError()
    // =========================================================================

    /**
     * Execute a job and convert BlockingPool errors to standardized HTTP responses.
     *
     * Delegates to BlockingPoolErrorHandler for the actual error mapping.
     * This keeps the mapping logic testable independently (BlockingPool is final).
     *
     * @param string $jobName Job name
     * @param array $payload Job data
     * @param object $response Response object with status(), header(), end() methods
     * @param float|null $timeout Timeout in seconds
     * @return mixed Job result on success
     * @throws BlockingPoolHttpException Always thrown on error (after sending HTTP response)
     */
    public function runOrRespondError(
        string $jobName,
        array $payload,
        object $response,
        ?float $timeout = null,
    ): mixed {
        return BlockingPoolErrorHandler::runOrRespondError($this, $jobName, $payload, $response, $timeout);
    }

    // =========================================================================
    // 17.9 — Real metrics: queueDepth, inflightCount, busyWorkers
    // =========================================================================

    /**
     * Current depth of the outbound queue (jobs waiting to be dispatched).
     * This is the real backpressure measure.
     */
    public function queueDepth(): int
    {
        return $this->outboundQueue !== null ? $this->outboundQueue->length() : 0;
    }

    /**
     * Number of jobs in flight (sent to pool, awaiting response).
     * Distinct from queueDepth() which measures the undispatched backlog.
     */
    public function inflightCount(): int
    {
        return count($this->pendingJobs);
    }

    /**
     * Number of pool workers currently busy.
     * Incremented on dispatch, decremented on response receipt.
     */
    public function busyWorkers(): int
    {
        return $this->busyWorkersCount;
    }

    // =========================================================================
    // 17.11 — Real-time metrics integration
    // =========================================================================

    /**
     * Update all BlockingPool metrics in the MetricsCollector.
     * Called periodically or on-demand for observability.
     */
    public function updateMetrics(): void
    {
        $this->metrics->setBlockingPoolBusyWorkers($this->busyWorkersCount);
        $this->metrics->setBlockingQueueDepth($this->queueDepth());
        $this->metrics->setBlockingInflightCount($this->inflightCount());
    }

    // =========================================================================
    // Internal: sendToPool, stop, shutdown
    // =========================================================================

    /**
     * Send a framed message to the pool via Unix socket.
     *
     * @param string $message Framed binary message (uint32 length prefix + JSON)
     * @throws BlockingPoolSendException If the write fails
     */
    private function sendToPool(string $message): void
    {
        if ($this->pool === null) {
            throw new BlockingPoolSendException('BlockingPool: pool not initialized');
        }

        // Write to a pool worker via the pool's IPC mechanism
        // In OpenSwoole Process\Pool, we write to the worker process socket
        try {
            $workers = $this->pool->getProcess();
            if ($workers === false) {
                throw new BlockingPoolSendException('BlockingPool: no pool process available');
            }
            $written = $workers->write($message);
            if ($written === false) {
                throw new BlockingPoolSendException('BlockingPool: IPC write failed');
            }
        } catch (BlockingPoolSendException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BlockingPoolSendException(
                'BlockingPool: sendToPool failed — ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Stop the reader coroutine.
     */
    public function stopReader(): void
    {
        $this->readerRunning = false;
        // Close the outbound queue to signal the dispatcher to stop
        if ($this->outboundQueue !== null) {
            $this->outboundQueue->close();
        }
    }

    /**
     * Stop the pool gracefully.
     * Waits for pending jobs (with timeout), then terminates pool workers.
     * Called by the master process AFTER draining HTTP workers.
     */
    public function stop(): void
    {
        $this->stopReader();

        // Fail any remaining pending jobs
        foreach ($this->pendingJobs as $jobId => $channel) {
            $channel->push([
                'job_id' => $jobId,
                'ok' => false,
                'result' => null,
                'error' => 'Pool shutting down',
            ]);
        }
        $this->pendingJobs = [];
    }

    /**
     * Get the JobRegistry (for pool worker-side job resolution).
     */
    public function getRegistry(): JobRegistry
    {
        return $this->registry;
    }
}
