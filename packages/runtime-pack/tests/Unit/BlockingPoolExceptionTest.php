<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack\Tests\Unit;

use AsyncPlatform\RuntimePack\Exception\BlockingPoolFullException;
use AsyncPlatform\RuntimePack\Exception\BlockingPoolHttpException;
use AsyncPlatform\RuntimePack\Exception\BlockingPoolSendException;
use AsyncPlatform\RuntimePack\Exception\BlockingPoolTimeoutException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BlockingPool exception classes.
 *
 * Validates: Exception hierarchy, httpStatusCode, message propagation.
 */
final class BlockingPoolExceptionTest extends TestCase
{
    #[Test]
    public function httpExceptionCarriesStatusCode(): void
    {
        $previous = new \RuntimeException('original');
        $e = new BlockingPoolHttpException(503, 'Queue full', $previous);

        self::assertSame(503, $e->httpStatusCode);
        self::assertSame('Queue full', $e->getMessage());
        self::assertSame(503, $e->getCode());
        self::assertSame($previous, $e->getPrevious());
    }

    #[Test]
    public function httpExceptionStatusCodes(): void
    {
        $cases = [
            [503, 'Service Unavailable'],
            [504, 'Gateway Timeout'],
            [502, 'Bad Gateway'],
            [500, 'Internal Server Error'],
        ];

        foreach ($cases as [$code, $message]) {
            $e = new BlockingPoolHttpException($code, $message);
            self::assertSame($code, $e->httpStatusCode);
            self::assertSame($message, $e->getMessage());
        }
    }

    #[Test]
    public function httpExceptionDefaultValues(): void
    {
        $e = new BlockingPoolHttpException(500);
        self::assertSame(500, $e->httpStatusCode);
        self::assertSame('', $e->getMessage());
        self::assertNull($e->getPrevious());
    }

    #[Test]
    public function sendExceptionIsRuntimeException(): void
    {
        $e = new BlockingPoolSendException('pool down');
        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('pool down', $e->getMessage());
    }

    #[Test]
    public function fullExceptionIsRuntimeException(): void
    {
        $e = new BlockingPoolFullException('queue full (64)');
        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('queue full (64)', $e->getMessage());
    }

    #[Test]
    public function timeoutExceptionIsRuntimeException(): void
    {
        $e = new BlockingPoolTimeoutException('job timed out after 30s');
        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('job timed out after 30s', $e->getMessage());
    }

    #[Test]
    public function httpExceptionChainsPreviousException(): void
    {
        $timeout = new BlockingPoolTimeoutException('timed out');
        $http = new BlockingPoolHttpException(504, 'Gateway Timeout', $timeout);

        self::assertSame($timeout, $http->getPrevious());
        self::assertInstanceOf(BlockingPoolTimeoutException::class, $http->getPrevious());
    }
}
