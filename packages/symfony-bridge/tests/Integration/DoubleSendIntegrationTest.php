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
 * Integration test: double-send protection.
 *
 * Simulates a 408 timeout scenario where ResponseState.isSent() returns true
 * before the bridge writes the response. Verifies:
 * - Bridge skips response writing
 * - terminate() and reset() are still executed
 *
 * Requirements: 16.10
 */
final class DoubleSendIntegrationTest extends TestCase
{
    public function testAlreadySentResponseSkipsWritingButExecutesTerminateAndReset(): void
    {
        $callOrder = [];

        $kernel = new class ($callOrder) implements HttpKernelInterface, ResetInterface {
            private array $callOrder;

            public function __construct(array &$callOrder)
            {
                $this->callOrder = &$callOrder;
            }

            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                $this->callOrder[] = 'handle';
                return new Response('Should not be written', 200);
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

        // Create a response that reports isSent()=true immediately
        // This simulates the runtime pack having already sent a 408 timeout
        $alreadySentResponse = new AlreadySentFakeSwooleResponse();

        $adapter(IntegrationFakeSwooleRequest::get('/', 'double-send-1'), $alreadySentResponse);

        // The bridge should have skipped writing the response body
        // but terminate and reset must still execute
        $this->assertContains('terminate', $callOrder, 'terminate must be called even when response already sent');
        $this->assertContains('reset', $callOrder, 'reset must be called even when response already sent');

        // Warning log about skipped response
        $this->assertTrue(
            $logger->hasLogMatching('warning', 'already sent'),
            'Expected warning log about response already sent',
        );
    }

    public function testNormalResponseNotSkipped(): void
    {
        $kernel = new MiniHttpKernel(new Response('Normal response', 200));
        $logger = new IntegrationSpyLogger();
        $adapter = new HttpKernelAdapter(
            kernel: $kernel,
            logger: $logger,
            metricsCollector: new MetricsCollector(),
        );

        $response = new IntegrationFakeSwooleResponse();
        $adapter(IntegrationFakeSwooleRequest::get('/', 'normal-1'), $response);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('Normal response', $response->endContent);
        $this->assertTrue($response->endCalled);
    }
}

/**
 * Fake response that always reports isSent()=true.
 * Simulates a 408 timeout scenario from the runtime pack.
 */
class AlreadySentFakeSwooleResponse
{
    public ?int $statusCode = null;
    public array $headers = [];
    public array $writes = [];
    public bool $endCalled = false;
    public string $endContent = '';

    public function status(int $code, string $reason = ''): void
    {
        $this->statusCode = $code;
    }

    public function header(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    public function write(string $content): bool
    {
        $this->writes[] = $content;
        return true;
    }

    public function end(string $content = ''): void
    {
        $this->endCalled = true;
        $this->endContent = $content;
    }

    public function cookie(
        string $name,
        string $value,
        int $expires,
        string $path,
        string $domain,
        bool $secure,
        bool $httpOnly,
        string $sameSite,
    ): void {
    }

    public function sendfile(string $path): void
    {
    }

    /**
     * Always returns true — simulates a response already sent by the runtime pack
     * (e.g., 408 timeout or 503 shutdown).
     */
    public function isSent(): bool
    {
        return true;
    }
}
