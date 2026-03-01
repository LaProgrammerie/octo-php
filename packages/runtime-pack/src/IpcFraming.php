<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack;

/**
 * IPC framing protocol: uint32 length prefix (big-endian) + JSON payload.
 *
 * Wire format:
 *   [4 bytes: payload length as uint32 BE] [N bytes: JSON payload]
 *
 * This is a pure-logic helper with no OpenSwoole dependency,
 * making it fully unit-testable.
 *
 * For binary payloads: use base64 encoding in the JSON payload
 * (field "encoding":"base64") or a separate "type":"binary" frame.
 */
final class IpcFraming
{
    /** Length of the uint32 header in bytes. */
    public const HEADER_SIZE = 4;

    /** Maximum payload size: 16 MB (guard against runaway allocations). */
    public const MAX_PAYLOAD_SIZE = 16 * 1024 * 1024;

    /**
     * Encode a payload array into a framed binary message.
     *
     * @param array $payload Data to serialize as JSON
     * @return string Binary frame: uint32 BE length prefix + JSON bytes
     * @throws \JsonException If JSON encoding fails
     */
    public static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $length = strlen($json);

        return pack('N', $length) . $json;
    }

    /**
     * Decode a complete framed message back into an array.
     *
     * Expects the full frame (header + payload). Use extractFromBuffer()
     * for incremental parsing from a stream buffer.
     *
     * @param string $frame Complete binary frame
     * @return array Decoded payload
     * @throws \RuntimeException If frame is too short or payload is truncated
     * @throws \JsonException If JSON decoding fails
     */
    public static function decode(string $frame): array
    {
        if (strlen($frame) < self::HEADER_SIZE) {
            throw new \RuntimeException(
                'IPC frame too short: expected at least ' . self::HEADER_SIZE . ' bytes, got ' . strlen($frame)
            );
        }

        $unpacked = unpack('Nlength', $frame);
        $length = $unpacked['length'];

        if ($length > self::MAX_PAYLOAD_SIZE) {
            throw new \RuntimeException(
                "IPC frame payload too large: {$length} bytes (max " . self::MAX_PAYLOAD_SIZE . ')'
            );
        }

        $expectedTotal = self::HEADER_SIZE + $length;
        if (strlen($frame) < $expectedTotal) {
            throw new \RuntimeException(
                "IPC frame incomplete: expected {$expectedTotal} bytes, got " . strlen($frame)
            );
        }

        $json = substr($frame, self::HEADER_SIZE, $length);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Try to extract one complete frame from a buffer.
     *
     * Used by the reader coroutine for incremental stream parsing.
     * If a complete frame is available, returns the decoded payload
     * and advances the buffer offset.
     *
     * @param string $buffer The accumulated read buffer (passed by reference)
     * @return array|null Decoded payload, or null if buffer doesn't contain a complete frame
     * @throws \RuntimeException If payload size exceeds MAX_PAYLOAD_SIZE
     * @throws \JsonException If JSON decoding fails
     */
    public static function extractFromBuffer(string &$buffer): ?array
    {
        if (strlen($buffer) < self::HEADER_SIZE) {
            return null;
        }

        $unpacked = unpack('Nlength', $buffer);
        $length = $unpacked['length'];

        if ($length > self::MAX_PAYLOAD_SIZE) {
            throw new \RuntimeException(
                "IPC frame payload too large: {$length} bytes (max " . self::MAX_PAYLOAD_SIZE . ')'
            );
        }

        $expectedTotal = self::HEADER_SIZE + $length;
        if (strlen($buffer) < $expectedTotal) {
            return null; // Not enough data yet — wait for more
        }

        $json = substr($buffer, self::HEADER_SIZE, $length);
        $buffer = substr($buffer, $expectedTotal); // Consume the frame

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Encode binary data with base64 for safe JSON transport.
     *
     * @param string $binaryData Raw binary data
     * @return array Payload with encoding metadata
     */
    public static function encodeBinaryPayload(string $binaryData): array
    {
        return [
            'type' => 'binary',
            'encoding' => 'base64',
            'data' => base64_encode($binaryData),
        ];
    }

    /**
     * Decode a binary payload that was encoded with encodeBinaryPayload().
     *
     * @param array $payload Payload with encoding metadata
     * @return string Raw binary data
     * @throws \RuntimeException If payload format is invalid
     */
    public static function decodeBinaryPayload(array $payload): string
    {
        if (($payload['type'] ?? '') !== 'binary' || ($payload['encoding'] ?? '') !== 'base64') {
            throw new \RuntimeException('Invalid binary payload: expected type=binary, encoding=base64');
        }

        $decoded = base64_decode($payload['data'] ?? '', true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64 data in binary payload');
        }

        return $decoded;
    }
}
