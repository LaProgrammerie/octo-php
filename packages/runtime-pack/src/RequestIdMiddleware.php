<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

use Psr\Log\LoggerInterface;

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
    private const MAX_LENGTH = 128;
    private const HEADER_NAME = 'x-request-id';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Extracts the X-Request-Id from the incoming request header or generates a new UUIDv4.
     *
     * If the incoming header value is invalid (too long, non-ASCII, or empty),
     * a new UUIDv4 is generated and a warning is logged (without reflecting the raw input).
     *
     * @param \OpenSwoole\Http\Request $request The incoming HTTP request
     * @return string A valid request ID (either the incoming value or a generated UUIDv4)
     */
    public function resolve(object $request): string
    {
        /** @var array<string, string>|null $headers */
        $headers = $request->header ?? [];
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
     */
    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);

        // Set version 4 bits (0100) in byte 6 (high nibble)
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);

        // Set variant 1 bits (10xx) in byte 8 (high 2 bits)
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        );
    }

    /**
     * Validates an incoming request ID value.
     *
     * Rules:
     * - Length must be <= 128 characters
     * - All characters must be printable ASCII (codes 32-126)
     */
    private function isValid(string $value): bool
    {
        if (strlen($value) > self::MAX_LENGTH) {
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
}
