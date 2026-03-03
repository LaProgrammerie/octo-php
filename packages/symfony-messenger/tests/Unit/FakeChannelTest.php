<?php

declare(strict_types=1);

namespace Octo\SymfonyMessenger\Tests\Unit;

use InvalidArgumentException;
use Octo\SymfonyMessenger\FakeChannel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FakeChannelTest extends TestCase
{
    #[Test]
    public function pushAndPopPreserveFifoOrder(): void
    {
        $channel = new FakeChannel(10);

        $channel->push('a');
        $channel->push('b');
        $channel->push('c');

        self::assertSame('a', $channel->pop());
        self::assertSame('b', $channel->pop());
        self::assertSame('c', $channel->pop());
    }

    #[Test]
    public function popReturnsFalseWhenEmpty(): void
    {
        $channel = new FakeChannel(10);

        self::assertFalse($channel->pop());
    }

    #[Test]
    public function pushReturnsFalseWhenFull(): void
    {
        $channel = new FakeChannel(2);

        self::assertTrue($channel->push('a'));
        self::assertTrue($channel->push('b'));
        self::assertFalse($channel->push('c'));
    }

    #[Test]
    public function lengthTracksCorrectly(): void
    {
        $channel = new FakeChannel(10);

        self::assertSame(0, $channel->length());

        $channel->push('a');
        self::assertSame(1, $channel->length());

        $channel->push('b');
        self::assertSame(2, $channel->length());

        $channel->pop();
        self::assertSame(1, $channel->length());
    }

    #[Test]
    public function capacityReturnsConfiguredValue(): void
    {
        $channel = new FakeChannel(42);
        self::assertSame(42, $channel->capacity());
    }

    #[Test]
    public function isFullReflectsCapacity(): void
    {
        $channel = new FakeChannel(2);

        self::assertFalse($channel->isFull());
        $channel->push('a');
        self::assertFalse($channel->isFull());
        $channel->push('b');
        self::assertTrue($channel->isFull());
    }

    #[Test]
    public function invalidCapacityThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FakeChannel(0);
    }
}
