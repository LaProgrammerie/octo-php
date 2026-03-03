<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Property;

use Octo\RuntimePack\RequestIdMiddleware;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Feature: runtime-pack-openswoole, Property 9: Résolution du Request ID
 *
 * **Validates: Requirements 10.1, 10.2, 10.3, 10.5**
 *
 * Property: For any arbitrary string input as X-Request-Id header:
 * - If valid (<=128 chars, all ASCII 32-126): resolve() returns the value unchanged
 * - If invalid: resolve() returns a valid UUIDv4
 * - Result is NEVER empty
 */
final class RequestIdResolutionTest extends TestCase
{
    use TestTrait;

    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private RequestIdMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new RequestIdMiddleware(new NullLogger());
    }

    /**
     * Creates a fake request object with the given X-Request-Id header value.
     */
    private function fakeRequest(?string $requestId): object
    {
        return new class ($requestId) {
            /** @var array<string, string> */
            public array $header;

            public function __construct(?string $requestId)
            {
                $this->header = $requestId !== null
                ? ['x-request-id' => $requestId]
                : [];
            }
        };
    }

    /**
     * Checks if a string is a valid request ID input (<=128 chars, ASCII 32-126).
     */
    private static function isValidRequestId(string $value): bool
    {
        if ($value === '' || strlen($value) > 128) {
            return false;
        }
        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $ord = ord($value[$i]);
            if ($ord < 32 || $ord > 126) {
                return false;
            }
        }
        return true;
    }

    /**
     * Property 9a: Valid request IDs are returned unchanged.
     *
     * Generate strings of printable ASCII chars (32-126), length 1-128.
     */
    #[Test]
    public function validRequestIdIsReturnedUnchanged(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(1, 128),  // length
        )->then(function (int $length): void {
            // Build a valid ASCII string of the given length
            $chars = '';
            for ($i = 0; $i < $length; $i++) {
                $chars .= chr(random_int(32, 126));
            }

            $request = $this->fakeRequest($chars);
            $result = $this->middleware->resolve($request);

            self::assertSame($chars, $result, 'Valid request ID should be returned unchanged');
            self::assertNotEmpty($result, 'Result must never be empty');
        });
    }

    /**
     * Property 9b: Invalid request IDs produce a valid UUIDv4.
     *
     * Generate strings that violate at least one rule:
     * - Too long (> 128 chars)
     * - Contains non-ASCII characters (outside 32-126)
     * - Empty string
     */
    #[Test]
    public function invalidRequestIdProducesValidUuidV4(): void
    {
        $this->limitTo(100);

        // Strategy: generate arbitrary byte strings that may contain non-ASCII
        $this->forAll(
            Generators::string(),
        )->then(function (string $input): void {
            if (self::isValidRequestId($input)) {
                // Skip valid inputs — we only care about invalid ones
                return;
            }

            $request = $this->fakeRequest($input);
            $result = $this->middleware->resolve($request);

            self::assertNotEmpty($result, 'Result must never be empty');
            self::assertMatchesRegularExpression(
                self::UUID_V4_PATTERN,
                $result,
                "Invalid input should produce a UUIDv4, got: '{$result}'",
            );
        });
    }

    /**
     * Property 9c: No header at all → generates a valid UUIDv4, never empty.
     */
    #[Test]
    public function noHeaderProducesValidUuidV4(): void
    {
        $this->limitTo(100);

        // Run 100 times to verify randomness produces valid UUIDs
        $this->forAll(
            Generators::constant(null),
        )->then(function ($ignored): void {
            $request = $this->fakeRequest(null);
            $result = $this->middleware->resolve($request);

            self::assertNotEmpty($result, 'Result must never be empty');
            self::assertMatchesRegularExpression(
                self::UUID_V4_PATTERN,
                $result,
                "Missing header should produce a UUIDv4, got: '{$result}'",
            );
        });
    }

    /**
     * Property 9d: Strings longer than 128 chars always produce UUIDv4.
     */
    #[Test]
    public function tooLongRequestIdProducesUuidV4(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::choose(129, 500),  // length > 128
        )->then(function (int $length): void {
            $chars = str_repeat('a', $length);

            $request = $this->fakeRequest($chars);
            $result = $this->middleware->resolve($request);

            self::assertNotEmpty($result, 'Result must never be empty');
            self::assertMatchesRegularExpression(
                self::UUID_V4_PATTERN,
                $result,
                "Too-long input ({$length} chars) should produce a UUIDv4",
            );
        });
    }
}
