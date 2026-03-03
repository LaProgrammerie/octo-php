<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge;

use Octo\RuntimePack\JsonLogger;
use Octo\RuntimePack\MetricsCollector;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Callable handler compatible with ServerBootstrap::run($handler).
 *
 * Invariant sequence per request:
 * 1. Extract request_id
 * 2. Convert OpenSwoole Request → HttpFoundation Request
 * 3. Check if response already sent (double-send protection)
 * 4. HttpKernel::handle()
 * 5. Convert HttpFoundation Response → OpenSwoole Response
 * 6. kernel->terminate($request, $response)
 * 7. ResetManager::reset()
 * 8. Metrics + memory surveillance + kernel reboot check
 *
 * No exception ever bubbles up to the runtime pack.
 */
final class HttpKernelAdapter
{
    private RequestConverter $requestConverter;
    private ResponseConverter $responseConverter;
    private ResetManager $resetManager;
    private RequestIdProcessor $requestIdProcessor;
    private ?MetricsBridge $metricsBridge;
    private int $kernelRebootEvery;
    private int $requestCount = 0;
    private bool $debug;
    private int $memoryWarningThreshold;

    public function __construct(
        private HttpKernelInterface $kernel,
        private LoggerInterface $logger,
        ?MetricsCollector $metricsCollector = null,
        int $kernelRebootEvery = 0,
        int $resetWarningMs = 50,
        bool $debug = false,
        int $memoryWarningThreshold = 104_857_600, // 100 MB
    ) {
        $this->requestConverter = new RequestConverter();
        $this->responseConverter = new ResponseConverter($logger);
        $this->metricsBridge = $metricsCollector !== null
            ? new MetricsBridge($metricsCollector)
            : null;
        $this->resetManager = new ResetManager(
            $kernel,
            $logger,
            $this->metricsBridge,
            $resetWarningMs,
        );
        $this->requestIdProcessor = new RequestIdProcessor();
        $this->kernelRebootEvery = $kernelRebootEvery;
        $this->debug = $debug;
        $this->memoryWarningThreshold = $memoryWarningThreshold;

        // If the logger is a JsonLogger from the runtime pack, set component
        if ($logger instanceof JsonLogger) {
            $this->logger = $logger->withComponent('symfony_bridge');
        }
    }

    /**
     * Entry point called by the runtime pack for each request.
     *
     * The handler receives ($swooleRequest, $swooleResponse) where both are
     * raw OpenSwoole objects. The ResponseConverter works directly with the
     * raw response object (which exposes status/header/write/end/cookie/sendfile).
     *
     * In production, the runtime pack's RequestHandler wraps the raw response
     * in a ResponseFacade before calling the app handler. The bridge's
     * ResponseConverter accepts any object with the facade interface (duck typing).
     *
     * For double-send protection, the bridge tracks whether it has already
     * written a response via an internal flag.
     *
     * @param object $swooleRequest  OpenSwoole\Http\Request
     * @param object $swooleResponse OpenSwoole\Http\Response (raw or ResponseFacade)
     */
    public function __invoke(object $swooleRequest, object $swooleResponse): void
    {
        $startTime = \microtime(true);
        $this->requestCount++;
        $exceptionClass = null;
        $statusCode = 200;
        $responseSent = false;

        // 1. Extract request_id
        $headers = $swooleRequest->header ?? [];
        $requestId = $headers['x-request-id'] ?? \bin2hex(\random_bytes(8));

        // Check if the response object exposes isSent() (ResponseFacade from runtime pack)
        $isAlreadySent = fn(): bool =>
            (\method_exists($swooleResponse, 'isSent') && $swooleResponse->isSent())
            || $responseSent;

        try {
            // 2. Convert request
            $sfRequest = $this->requestConverter->convert($swooleRequest);
            if (!$sfRequest->attributes->has('_request_id')) {
                $sfRequest->attributes->set('_request_id', $requestId);
            } else {
                $requestId = $sfRequest->attributes->get('_request_id');
            }

            // Set current request on the RequestIdProcessor
            $this->requestIdProcessor->setCurrentRequest($sfRequest);

            // 3. Check if response already sent (408 timeout / 503 shutdown)
            if ($isAlreadySent()) {
                $this->logger->warning('Response already sent by runtime pack, skipping response writing', [
                    'request_id' => $requestId,
                    'component' => 'symfony_bridge',
                ]);
                $sfResponse = new Response('', 200);
                $statusCode = 200;
            } else {
                // 4. HttpKernel::handle()
                try {
                    $sfResponse = $this->kernel->handle(
                        $sfRequest,
                        HttpKernelInterface::MAIN_REQUEST,
                    );
                    $statusCode = $sfResponse->getStatusCode();
                } catch (\Throwable $e) {
                    $exceptionClass = $e::class;
                    $this->metricsBridge?->incrementExceptions();
                    $sfResponse = $this->handleException($e, $requestId);
                    $statusCode = $sfResponse->getStatusCode();
                }

                // 5. Convert response → OpenSwoole (if not already sent)
                if (!$isAlreadySent()) {
                    $this->responseConverter->convert($sfResponse, $swooleResponse, $swooleResponse);
                    $responseSent = true;
                } else {
                    $this->logger->warning('Response already sent by runtime pack, skipping response writing', [
                        'request_id' => $requestId,
                        'component' => 'symfony_bridge',
                    ]);
                }
            }

            // 6. kernel->terminate()
            if (\method_exists($this->kernel, 'terminate')) {
                $this->kernel->terminate($sfRequest, $sfResponse);
            }
        } catch (\Throwable $e) {
            // Catch-all: no exception must bubble up to the runtime pack
            $exceptionClass = $exceptionClass ?? $e::class;
            $this->metricsBridge?->incrementExceptions();
            $this->logger->error('Unhandled exception in HttpKernelAdapter', [
                'request_id' => $requestId,
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
                'component' => 'symfony_bridge',
            ]);

            // Try to send a 500 if response not yet sent
            if (!$isAlreadySent()) {
                $statusCode = 500;
                try {
                    $swooleResponse->status(500);
                    $swooleResponse->header('Content-Type', 'application/json');
                    $swooleResponse->end(\json_encode(
                        ['error' => 'Internal Server Error'],
                        \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                    ));
                    $responseSent = true;
                } catch (\Throwable) {
                    // Response write failed — nothing more we can do
                }
            }
        } finally {
            // 7. ResetManager::reset() — ALWAYS executed
            try {
                $this->resetManager->reset($requestId);
            } catch (\Throwable $e) {
                $this->logger->error('Reset failed in finally block', [
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                    'component' => 'symfony_bridge',
                ]);
            }

            // Clear the current request from the processor
            $this->requestIdProcessor->setCurrentRequest(null);

            // 8. Metrics
            $durationMs = (\microtime(true) - $startTime) * 1000;
            $this->metricsBridge?->incrementRequests();
            $this->metricsBridge?->recordRequestDuration($durationMs);

            // Memory surveillance post-reset
            $this->measureMemory($requestId);

            // End-of-request log
            $logContext = [
                'request_id' => $requestId,
                'status_code' => $statusCode,
                'duration_ms' => \round($durationMs, 2),
                'component' => 'symfony_bridge',
            ];
            if ($exceptionClass !== null) {
                $logContext['exception_class'] = $exceptionClass;
            }
            $this->logger->info('Request completed', $logContext);

            // Kernel reboot check
            if ($this->kernelRebootEvery > 0 && ($this->requestCount % $this->kernelRebootEvery) === 0) {
                $this->rebootKernel($requestId);
            }
        }
    }

    public function getResetManager(): ResetManager
    {
        return $this->resetManager;
    }

    public function getRequestIdProcessor(): RequestIdProcessor
    {
        return $this->requestIdProcessor;
    }

    public function getMetricsBridge(): ?MetricsBridge
    {
        return $this->metricsBridge;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    /**
     * Handles an exception and produces an HTTP response.
     *
     * Prod: 500 JSON generic {"error":"Internal Server Error"} — no stacktrace.
     * Dev: Symfony error page with stacktrace via HtmlErrorRenderer.
     */
    private function handleException(\Throwable $e, string $requestId): Response
    {
        $this->logger->error('Exception during request handling', [
            'request_id' => $requestId,
            'exception_class' => $e::class,
            'error' => $e->getMessage(),
            'component' => 'symfony_bridge',
        ]);

        if ($this->debug) {
            return $this->renderDevError($e);
        }

        return new Response(
            \json_encode(
                ['error' => 'Internal Server Error'],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ),
            500,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Renders a dev-mode error page using Symfony ErrorHandler.
     */
    private function renderDevError(\Throwable $e): Response
    {
        try {
            if (\class_exists(\Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer::class)) {
                $renderer = new \Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer(true);
                $rendered = $renderer->render($e);

                return new Response(
                    $rendered->getAsString(),
                    $rendered->getStatusCode(),
                    $rendered->getHeaders(),
                );
            }
        } catch (\Throwable) {
            // Fall through to generic response
        }

        // Fallback: plain text with stacktrace
        return new Response(
            \sprintf(
                "<pre>%s: %s\n\n%s</pre>",
                \htmlspecialchars($e::class, \ENT_QUOTES),
                \htmlspecialchars($e->getMessage(), \ENT_QUOTES),
                \htmlspecialchars($e->getTraceAsString(), \ENT_QUOTES),
            ),
            500,
            ['Content-Type' => 'text/html'],
        );
    }

    /**
     * Reboots the Symfony kernel and rebuilds internal references.
     */
    private function rebootKernel(string $requestId): void
    {
        try {
            $this->logger->info('Kernel reboot triggered', [
                'request_id' => $requestId,
                'request_count' => $this->requestCount,
                'component' => 'symfony_bridge',
            ]);

            if (\method_exists($this->kernel, 'shutdown')) {
                $this->kernel->shutdown();
            }
            if (\method_exists($this->kernel, 'boot')) {
                $this->kernel->boot();
            }

            $this->rebuildReferences();
        } catch (\Throwable $e) {
            $this->logger->error('Kernel reboot failed', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'component' => 'symfony_bridge',
            ]);
        }
    }

    /**
     * Rebuilds internal references after a kernel reboot.
     */
    private function rebuildReferences(): void
    {
        $this->resetManager = new ResetManager(
            $this->kernel,
            $this->logger,
            $this->metricsBridge,
            $this->resetManager->getResetWarningMs(),
        );
        $this->requestIdProcessor = new RequestIdProcessor();
        $this->responseConverter = new ResponseConverter($this->logger);
    }

    /**
     * Measures RSS memory after reset and logs warning if above threshold.
     */
    private function measureMemory(string $requestId): void
    {
        $rssBytes = \memory_get_usage(true);

        $this->metricsBridge?->recordMemoryRss($rssBytes);

        if ($rssBytes > $this->memoryWarningThreshold) {
            $this->logger->warning('Memory RSS exceeds threshold after reset', [
                'request_id' => $requestId,
                'memory_rss_bytes' => $rssBytes,
                'threshold_bytes' => $this->memoryWarningThreshold,
                'request_count' => $this->requestCount,
                'component' => 'symfony_bridge',
            ]);
        }
    }
}
