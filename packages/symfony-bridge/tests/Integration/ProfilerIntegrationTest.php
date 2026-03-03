<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Integration;

require_once __DIR__ . '/IntegrationTestDoubles.php';

use Octo\RuntimePack\MetricsCollector;
use Octo\SymfonyBridge\HttpKernelAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Integration test: Profiler lifecycle compatibility.
 *
 * Verifies that the terminate() → reset() ordering is correct for the
 * Symfony Profiler to work. The Profiler collects data during terminate(),
 * so terminate must happen BEFORE reset.
 *
 * Also verifies that data collectors are reset between requests (via the
 * kernel reset mechanism).
 *
 * Requirements: 9.1, 9.2, 9.3, 9.4, 16.11
 */
final class ProfilerIntegrationTest extends TestCase
{
    public function testTerminateCalledBeforeReset(): void
    {
        $callOrder = [];

        $kernel = new class($callOrder) implements HttpKernelInterface, ResetInterface {
            /** @var list<string> */
            private array $callOrder;

            public function __construct(array &$callOrder)
            {
                $this->callOrder = &$callOrder;
            }

            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                $this->callOrder[] = 'handle';

                return new Response('<html><body>Page</body></html>', 200, ['Content-Type' => 'text/html']);
            }

            public function terminate(Request $request, Response $response): void
            {
                $this->callOrder[] = 'terminate';
            }

            public function reset(): void
            {
                $this->callOrder[] = 'reset';
            }
        };

        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
        );

        $adapter(IntegrationFakeSwooleRequest::get('/', 'prof-1'), new IntegrationFakeSwooleResponse());

        // The invariant: handle → terminate → reset
        self::assertSame(['handle', 'terminate', 'reset'], $callOrder);
    }

    public function testResetCalledBetweenRequests(): void
    {
        $resetCounts = [];

        $kernel = new class($resetCounts) implements HttpKernelInterface, ResetInterface {
            private array $resetCounts;
            private int $requestNum = 0;

            public function __construct(array &$resetCounts)
            {
                $this->resetCounts = &$resetCounts;
            }

            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                ++$this->requestNum;

                return new Response("Request {$this->requestNum}");
            }

            public function terminate(Request $request, Response $response): void {}

            public function reset(): void
            {
                $this->resetCounts[] = $this->requestNum;
            }
        };

        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
        );

        // Send 3 requests
        for ($i = 0; $i < 3; ++$i) {
            $adapter(IntegrationFakeSwooleRequest::get('/', "prof-multi-{$i}"), new IntegrationFakeSwooleResponse());
        }

        // Reset called after each request (at request 1, 2, 3)
        self::assertSame([1, 2, 3], $resetCounts);
    }

    public function testHtmlResponsePreservedForProfilerToolbar(): void
    {
        $htmlBody = '<!DOCTYPE html><html><head></head><body><h1>Page</h1></body></html>';
        $kernel = new MiniHttpKernel(new Response($htmlBody, 200, ['Content-Type' => 'text/html']));
        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
        );

        $response = new IntegrationFakeSwooleResponse();
        $adapter(IntegrationFakeSwooleRequest::get('/', 'prof-html'), $response);

        // The HTML body is preserved intact — Profiler toolbar injection
        // happens inside Symfony's terminate() via WebDebugToolbarListener,
        // which modifies the Response before our ResponseConverter sees it.
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('<body>', $response->endContent);
    }
}
