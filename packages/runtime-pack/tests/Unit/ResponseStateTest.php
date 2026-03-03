<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use Octo\RuntimePack\ResponseState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ResponseState.
 *
 * ResponseState is pure logic (no OpenSwoole dependency), so it can be
 * fully tested without the extension.
 */
final class ResponseStateTest extends TestCase
{
    // --- trySend() ---

    public function testTrySendReturnsTrueOnFirstCall(): void
    {
        $state = new ResponseState();
        self::assertTrue($state->trySend());
    }

    public function testTrySendReturnsFalseOnSubsequentCalls(): void
    {
        $state = new ResponseState();
        $state->trySend();
        self::assertFalse($state->trySend());
        self::assertFalse($state->trySend());
    }

    public function testIsSentFalseInitially(): void
    {
        $state = new ResponseState();
        self::assertFalse($state->isSent());
    }

    public function testIsSentTrueAfterTrySend(): void
    {
        $state = new ResponseState();
        $state->trySend();
        self::assertTrue($state->isSent());
    }

    // --- statusCode tracking ---

    public function testGetStatusCodeReturns200ByDefault(): void
    {
        $state = new ResponseState();
        self::assertSame(200, $state->getStatusCode());
    }

    public function testSetStatusCodeUpdatesValue(): void
    {
        $state = new ResponseState();
        $state->setStatusCode(404);
        self::assertSame(404, $state->getStatusCode());
    }

    public function testSetStatusCodeCanBeOverwritten(): void
    {
        $state = new ResponseState();
        $state->setStatusCode(408);
        $state->setStatusCode(500);
        self::assertSame(500, $state->getStatusCode());
    }

    // --- hasExplicitStatusCode() ---

    public function testHasExplicitStatusCodeFalseByDefault(): void
    {
        $state = new ResponseState();
        self::assertFalse($state->hasExplicitStatusCode());
    }

    public function testHasExplicitStatusCodeTrueAfterSetStatusCode(): void
    {
        $state = new ResponseState();
        $state->setStatusCode(200);
        self::assertTrue($state->hasExplicitStatusCode());
    }

    public function testHasExplicitStatusCodeTrueAfterSetStatusCode500(): void
    {
        $state = new ResponseState();
        $state->setStatusCode(500);
        self::assertTrue($state->hasExplicitStatusCode());
    }

    // --- Combined scenarios ---

    public function testStatusCodePreservedAfterTrySend(): void
    {
        $state = new ResponseState();
        $state->setStatusCode(201);
        $state->trySend();
        self::assertSame(201, $state->getStatusCode());
    }

    public function testStatusCodeSetBeforeTrySendIsPreserved(): void
    {
        // Simulates the timer ordering: setStatusCode(408) BEFORE trySend()
        $state = new ResponseState();
        $state->setStatusCode(408);
        self::assertTrue($state->trySend());
        self::assertSame(408, $state->getStatusCode());
        self::assertTrue($state->hasExplicitStatusCode());
    }

    public function testSecondTrySendDoesNotAffectStatusCode(): void
    {
        // First caller sets 408 and wins trySend
        $state = new ResponseState();
        $state->setStatusCode(408);
        self::assertTrue($state->trySend());

        // Second caller sets 500 but loses trySend
        $state->setStatusCode(500);
        self::assertFalse($state->trySend());
        // statusCode reflects the last setStatusCode call
        self::assertSame(500, $state->getStatusCode());
    }
}
