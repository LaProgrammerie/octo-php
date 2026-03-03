<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use Octo\RuntimePack\BlockingPoolErrorHandler;
use Octo\RuntimePack\BlockingPoolInterface;
use Octo\RuntimePack\Exception\BlockingPoolFullException;
use Octo\RuntimePack\Exception\BlockingPoolHttpException;
use Octo\RuntimePack\Exception\BlockingPoolSendException;
use Octo\RuntimePack\Exception\BlockingPoolTimeoutException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Unit tests for BlockingPoolErrorHandler::runOrRespondError().
 *
 * Tests the standardized error mapping:
 * - Full → 503 + Retry-After + Content-Type
 * - Timeout → 504 + Content-Type
 * - SendFailed → 502 + Content-Type
 * - RuntimeException → 500 + Content-Type
 * - Success → result returned, response untouched
 */
final class BlockingPoolErrorHandlerTest extends TestCase
{
    #[Test]
    public function mapsFullExceptionTo503WithRetryAfter(): void
    {
        $pool = $this->createMockPool(new BlockingPoolFullException('queue full'));
        $response = $this->createMockResponse();

        $response->expects(self::once())->method('status')->with(503);

        $headerCalls = [];
        $response->method('header')->willReturnCallback(
            static function (string $key, string $value) use (&$headerCalls): void {
                $headerCalls[$key] = $value;
            },
        );

        $response->expects(self::once())->method('end')
            ->with(self::stringContains('pool saturated'))
        ;

        try {
            BlockingPoolErrorHandler::runOrRespondError($pool, 'test.job', [], $response);
            self::fail('Expected BlockingPoolHttpException');
        } catch (BlockingPoolHttpException $e) {
            self::assertSame(503, $e->httpStatusCode);
            self::assertInstanceOf(BlockingPoolFullException::class, $e->getPrevious());
        }

        self::assertSame('application/json', $headerCalls['Content-Type'] ?? null);
        self::assertSame('5', $headerCalls['Retry-After'] ?? null);
    }

    #[Test]
    public function mapsTimeoutExceptionTo504(): void
    {
        $pool = $this->createMockPool(new BlockingPoolTimeoutException('timed out'));
        $response = $this->createMockResponse();

        $response->expects(self::once())->method('status')->with(504);
        $response->expects(self::once())->method('header')
            ->with('Content-Type', 'application/json')
        ;
        $response->expects(self::once())->method('end')
            ->with(self::stringContains('Gateway Timeout'))
        ;

        try {
            BlockingPoolErrorHandler::runOrRespondError($pool, 'test.job', [], $response);
            self::fail('Expected BlockingPoolHttpException');
        } catch (BlockingPoolHttpException $e) {
            self::assertSame(504, $e->httpStatusCode);
            self::assertInstanceOf(BlockingPoolTimeoutException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function mapsSendExceptionTo502(): void
    {
        $pool = $this->createMockPool(new BlockingPoolSendException('broken socket'));
        $response = $this->createMockResponse();

        $response->expects(self::once())->method('status')->with(502);
        $response->expects(self::once())->method('header')
            ->with('Content-Type', 'application/json')
        ;
        $response->expects(self::once())->method('end')
            ->with(self::stringContains('Bad Gateway'))
        ;

        try {
            BlockingPoolErrorHandler::runOrRespondError($pool, 'test.job', [], $response);
            self::fail('Expected BlockingPoolHttpException');
        } catch (BlockingPoolHttpException $e) {
            self::assertSame(502, $e->httpStatusCode);
            self::assertInstanceOf(BlockingPoolSendException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function mapsRuntimeExceptionTo500(): void
    {
        $pool = $this->createMockPool(new RuntimeException('job crashed'));
        $response = $this->createMockResponse();

        $response->expects(self::once())->method('status')->with(500);
        $response->expects(self::once())->method('header')
            ->with('Content-Type', 'application/json')
        ;
        $response->expects(self::once())->method('end')
            ->with(self::stringContains('Internal Server Error'))
        ;

        try {
            BlockingPoolErrorHandler::runOrRespondError($pool, 'test.job', [], $response);
            self::fail('Expected BlockingPoolHttpException');
        } catch (BlockingPoolHttpException $e) {
            self::assertSame(500, $e->httpStatusCode);
        }
    }

    #[Test]
    public function returnsResultOnSuccess(): void
    {
        $pool = $this->createSuccessPool(['data' => 42]);
        $response = $this->createMockResponse();

        $response->expects(self::never())->method('status');
        $response->expects(self::never())->method('header');
        $response->expects(self::never())->method('end');

        $result = BlockingPoolErrorHandler::runOrRespondError($pool, 'test.job', ['x' => 1], $response);
        self::assertSame(['data' => 42], $result);
    }

    #[Test]
    public function passesTimeoutToPool(): void
    {
        $pool = $this->createMock(BlockingPoolInterface::class);
        $pool->expects(self::once())
            ->method('run')
            ->with('test.job', ['key' => 'val'], 15.0)
            ->willReturn('ok')
        ;

        $response = $this->createMockResponse();

        $result = BlockingPoolErrorHandler::runOrRespondError($pool, 'test.job', ['key' => 'val'], $response, 15.0);
        self::assertSame('ok', $result);
    }

    #[Test]
    public function httpExceptionPreservesOriginalMessage(): void
    {
        $originalMsg = 'BlockingPool queue full (64)';
        $pool = $this->createMockPool(new BlockingPoolFullException($originalMsg));
        $response = $this->createMockResponse();

        try {
            BlockingPoolErrorHandler::runOrRespondError($pool, 'test.job', [], $response);
            self::fail('Expected BlockingPoolHttpException');
        } catch (BlockingPoolHttpException $e) {
            self::assertSame($originalMsg, $e->getMessage());
        }
    }

    private function createMockPool(Throwable $exception): BlockingPoolInterface
    {
        $mock = $this->createMock(BlockingPoolInterface::class);
        $mock->method('run')->willThrowException($exception);

        return $mock;
    }

    private function createSuccessPool(mixed $result): BlockingPoolInterface
    {
        $mock = $this->createMock(BlockingPoolInterface::class);
        $mock->method('run')->willReturn($result);

        return $mock;
    }

    private function createMockResponse(): MockObject
    {
        return $this->getMockBuilder(stdClass::class)
            ->addMethods(['status', 'header', 'end'])
            ->getMock();
    }
}
