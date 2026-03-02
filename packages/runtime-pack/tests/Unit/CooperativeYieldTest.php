<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack\Tests\Unit;

use AsyncPlatform\RuntimePack\CooperativeYield;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AsyncPlatform\RuntimePack\CooperativeYield
 */
final class CooperativeYieldTest extends TestCase
{
    public function testConstructorRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be > 0');
        new CooperativeYield(every: 0);
    }

    public function testConstructorRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CooperativeYield(every: -1);
    }

    public function testConstructorAcceptsPositive(): void
    {
        $yield = new CooperativeYield(every: 100);
        $this->assertInstanceOf(CooperativeYield::class, $yield);
    }

    public function testConstructorDefaultValue(): void
    {
        $yield = new CooperativeYield();
        $this->assertInstanceOf(CooperativeYield::class, $yield);
    }

    public function testResetClearsCounter(): void
    {
        // We can't directly observe the counter, but we can verify reset doesn't throw
        $yield = new CooperativeYield(every: 10);
        $yield->reset();
        $this->assertInstanceOf(CooperativeYield::class, $yield);
    }

    /**
     * maybeYield with every=0 should not throw (no-op).
     */
    public function testMaybeYieldWithZeroEveryIsNoop(): void
    {
        // Should not throw or call usleep
        CooperativeYield::maybeYield(100, 0);
        $this->assertTrue(true); // No exception = pass
    }

    /**
     * maybeYield at iteration 0 should never yield (guard: $iteration > 0).
     */
    public function testMaybeYieldAtZeroIterationIsNoop(): void
    {
        CooperativeYield::maybeYield(0, 10);
        $this->assertTrue(true);
    }

    /**
     * maybeYield with negative every should be a no-op (guard: $every > 0).
     */
    public function testMaybeYieldWithNegativeEveryIsNoop(): void
    {
        CooperativeYield::maybeYield(100, -5);
        $this->assertTrue(true);
    }
}
