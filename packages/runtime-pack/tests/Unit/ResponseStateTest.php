<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack\Tests\Unit;

use AsyncPlatform\RuntimePack\ResponseState;
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
        $this->assertTrue($state->trySend());
    }

    public function testTrySendReturnsFalseOnSubsequentCalls(): void
    {
        $state = new ResponseState();
        $state->trySend();
        $this->assertFalse($state->trySend());
        $this->assertFalse($state->trySend());
    }

    public function testIsSentFalseInitially(): void
    {
        $state = new ResponseState();
        $this->assertFalse($state->isSent());
    }

    public function testIsSentTrueAfterTrySend(): void
    {
        $state = new ResponseState();
        $state->trySend();
        $this->assertTrue($state->isSent());
    }

    // --- statusCode tracking ---

    public function testGetStatusCodeReturns200ByDefault(): void
    {
        $state = new ResponseState();
        $this->assertSame(200, $state->getStatusCode());
    }

    public function testSetStatusCodeUpdatesValue(): void
    {
        $state = new ResponseState();
        $state->setStatusCode(404);
        $this->assertSame(404, $state->getStatusCode());
    }

    public function testSetStatusCodeCanBeOverwritten(): void
    {
        $state = new ResponseState();
        $state->setStatusCode(408);
        $state->setStatusCode(500);
        $this->assertSame(500, $state->getStatusCode());
    }

    // --- hasExplicitStatusCode() ---

    public function testHasExplicitStatusCodeFalseByDefault(): void
    {
        $state = new ResponseState();
        $this->assertFalse($state->hasExplicitStatusCode());
    }

    public function testHasExplicitStatusCodeTrueAfterSetStatusCode(): void
    {
        $state = new ResponseState();
        $state->setStatusCode(200);
        $this->assertTrue($state->hasExplicitStatusCode());
    }

    public function testHasExplicitStatusCodeTrueAfterSetStatusCode500(): void
    {
        $state = new ResponseState();
        $state->setStatusCode(500);
        $this->assertTrue($state->hasExplicitStatusCode());
    }

    // --- Combined scenarios ---

    public function testStatusCodePreservedAfterTrySend(): void
    {
        $state = new ResponseState();
        $state->setStatusCode(201);
        $state->trySend();
        $this->assertSame(201, $state->getStatusCode());
    }

    public function testStatusCodeSetBeforeTrySendIsPreserved(): void
    {
        // Simulates the timer ordering: setStatusCode(408) BEFORE trySend()
        $state = new ResponseState();
        $state->setStatusCode(408);
        $this->assertTrue($state->trySend());
        $this->assertSame(408, $state->getStatusCode());
        $this->assertTrue($state->hasExplicitStatusCode());
    }

    public function testSecondTrySendDoesNotAffectStatusCode(): void
    {
        // First caller sets 408 and wins trySend
        $state = new ResponseState();
        $state->setStatusCode(408);
        $this->assertTrue($state->trySend());

        // Second caller sets 500 but loses trySend
        $state->setStatusCode(500);
        $this->assertFalse($state->trySend());
        // statusCode reflects the last setStatusCode call
        $this->assertSame(500, $state->getStatusCode());
    }
}
