<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Octo\RuntimePack\JobRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JobRegistry.
 *
 * Validates: job registration, resolution, duplicate detection, listing.
 */
final class JobRegistryTest extends TestCase
{
    #[Test]
    public function registerAndResolveJob(): void
    {
        $registry = new JobRegistry();
        $handler = static fn (array $p) => $p['x'] * 2;

        $registry->register('math.double', $handler);

        $resolved = $registry->resolve('math.double');
        self::assertSame(20, $resolved(['x' => 10]));
    }

    #[Test]
    public function hasReturnsTrueForRegisteredJob(): void
    {
        $registry = new JobRegistry();
        $registry->register('test.job', static fn () => null);

        self::assertTrue($registry->has('test.job'));
        self::assertFalse($registry->has('nonexistent'));
    }

    #[Test]
    public function namesReturnsRegisteredJobNames(): void
    {
        $registry = new JobRegistry();
        $registry->register('a.job', static fn () => null);
        $registry->register('b.job', static fn () => null);

        self::assertSame(['a.job', 'b.job'], $registry->names());
    }

    #[Test]
    public function resolveThrowsForUnknownJob(): void
    {
        $registry = new JobRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown job: 'missing'");

        $registry->resolve('missing');
    }

    #[Test]
    public function registerThrowsOnDuplicateName(): void
    {
        $registry = new JobRegistry();
        $registry->register('dup.job', static fn () => 1);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Job 'dup.job' already registered");

        $registry->register('dup.job', static fn () => 2);
    }

    #[Test]
    public function emptyRegistryHasNoNames(): void
    {
        $registry = new JobRegistry();

        self::assertSame([], $registry->names());
        self::assertFalse($registry->has('anything'));
    }
}
