<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

use OpenSwoole\Http\Request;
use Psr\Log\LoggerInterface;

use function chr;
use function ord;
use function sprintf;

/**
 * Extracts or generates a unique request ID (UUIDv4) for each HTTP request.
 *
 * Validation rules:
 * - Max 128 characters
 * - ASCII only (codes 32-126)
 * - Invalid values are rejected with a warning log (raw input is NOT reflected in the log)
 *
 * The resolved request ID is intended to be propagated as the X-Request-Id response header
 * by the caller (RequestHandler).
 */
final class RequestIdMiddleware
{
    private const HEADER_NAME = 'x-request-id';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Extracts the X-Request-Id from the incoming request header or generates a new UUIDv4.
     *
     * If the incoming header value is invalid (too long, non-ASCII, or empty),
     * a new UUIDv4 is generated and a warning is logged (without reflecting the raw input).
     *
     * @param Request $request The incoming HTTP request
     *
     * @return string A valid request ID (either the incoming value or a generated UUIDv4)
     */
    public function resolve(object $request): string
    {
        $headers = $request->header;
        $value = $headers[self::HEADER_NAME] ?? null;

        if ($value === null || $value === '') {
            return $this->generateUuidV4();
        }

        if ($this->isValid($value)) {
            return $value;
        }

        $this->logger->warning('Incoming X-Request-Id rejected: invalid format (too long or non-ASCII characters)');

        return $this->generateUuidV4();
    }

    /**
     * Generates a UUIDv4 using random_bytes(16) with version 4 and variant 1 bits.
     *
     * Format: xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx
     *
     * Uses unpack() instead of substr() to extract byte slices from binary data.
     * CS Fixer's mb_str_functions rule converts substr→mb_substr, which corrupts
     * bytes > 0x7F to 0x3F ('?') due to multibyte encoding interpretation.
     */
    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);

        // Set version 4 bits (0100) in byte 6 (high nibble)
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);

        // Set variant 1 bits (10xx) in byte 8 (high 2 bits)
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        /** @var array{a: string, b: string, c: string, d: string, e: string} $parts */
        $parts = unpack('a4a/a2b/a2c/a2d/a6e', $bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex($parts['a']),
            bin2hex($parts['b']),
            bin2hex($parts['c']),
            bin2hex($parts['d']),
            bin2hex($parts['e']),
        );
    }

    /**
     * Validates an incoming request ID value.
     *
     * Rules:
     * - Byte length must be <= 128
     * - All bytes must be printable ASCII (codes 32-126)
     *
     * Uses preg_match instead of strlen+loop to avoid CS Fixer's mb_str_functions
     * rule converting strlen→mb_strlen (which counts characters, not bytes, and
     * would skip trailing bytes in multibyte strings).
     */
    private function isValid(string $value): bool
    {
        // Single regex: 1-128 printable ASCII bytes (0x20-0x7E)
        return preg_match('/\A[\x20-\x7E]{1,128}\z/', $value) === 1;
    }
}
