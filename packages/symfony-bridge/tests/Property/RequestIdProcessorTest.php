<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge\Tests\Property;

use AsyncPlatform\SymfonyBridge\RequestIdProcessor;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Property 16: RequestIdProcessor adds request_id to log records
 *
 * **Validates: Requirements 6.6**
 *
 * For any LogRecord processed by the RequestIdProcessor when a current request
 * is defined with a _request_id, the record SHALL contain the field
 * extra.request_id with the value of the request_id.
 */
final class RequestIdProcessorTest extends TestCase
{
    use TestTrait;

    #[Test]
    public function request_id_is_added_to_all_log_records(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::suchThat(
                fn(string $s): bool => \strlen($s) > 0 && \mb_check_encoding($s, 'UTF-8'),
                Generators::string(),
            ),
            Generators::elements(['debug', 'info', 'warning', 'error', 'critical']),
            Generators::elements(['User logged in', 'Query executed', 'Cache miss', 'Request processed']),
        )->then(function (string $requestId, string $level, string $message): void {
            $processor = new RequestIdProcessor();

            // Create a request with _request_id attribute
            $request = Request::create('/test');
            $request->attributes->set('_request_id', $requestId);
            $processor->setCurrentRequest($request);

            // Simulate a Monolog-like array record (legacy format)
            $record = [
                'message' => $message,
                'level' => $level,
                'context' => [],
                'extra' => [],
            ];

            $result = $processor($record);

            // extra.request_id must be set
            self::assertArrayHasKey(
                'request_id',
                $result['extra'],
                'extra.request_id must be present'
            );
            self::assertSame(
                $requestId,
                $result['extra']['request_id'],
                'extra.request_id must match the request attribute'
            );
        });
    }

    #[Test]
    public function no_request_id_when_no_current_request(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::elements(['debug', 'info', 'warning', 'error']),
        )->then(function (string $level): void {
            $processor = new RequestIdProcessor();
            // No current request set

            $record = [
                'message' => 'test',
                'level' => $level,
                'context' => [],
                'extra' => [],
            ];

            $result = $processor($record);

            // extra.request_id must NOT be set
            self::assertArrayNotHasKey(
                'request_id',
                $result['extra'],
                'extra.request_id must not be present when no request is set'
            );
        });
    }
}
