<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use JsonException;
use Octo\RuntimePack\IpcFraming;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for IpcFraming — pure logic, no OpenSwoole dependency.
 *
 * Validates: IPC framing protocol (uint32 length prefix + JSON payload).
 */
final class IpcFramingTest extends TestCase
{
    #[Test]
    public function encodeProducesCorrectFrameFormat(): void
    {
        $payload = ['job_id' => 'test_123', 'job_name' => 'compute', 'payload' => ['x' => 1]];
        $frame = IpcFraming::encode($payload);

        // First 4 bytes = uint32 BE length prefix
        self::assertGreaterThanOrEqual(IpcFraming::HEADER_SIZE, mb_strlen($frame));

        $unpacked = unpack('Nlength', $frame);
        $jsonLength = $unpacked['length'];

        // Length prefix matches actual JSON payload length
        $jsonPart = mb_substr($frame, IpcFraming::HEADER_SIZE);
        self::assertSame($jsonLength, mb_strlen($jsonPart));

        // JSON is valid and matches input
        $decoded = json_decode($jsonPart, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($payload, $decoded);
    }

    #[Test]
    public function decodeRoundTripsWithEncode(): void
    {
        $payload = [
            'job_id' => 'job_abc_def',
            'job_name' => 'pdf.generate',
            'payload' => ['html' => '<h1>Hello</h1>', 'options' => ['format' => 'A4']],
        ];

        $frame = IpcFraming::encode($payload);
        $decoded = IpcFraming::decode($frame);

        self::assertSame($payload, $decoded);
    }

    #[Test]
    public function decodeThrowsOnTooShortFrame(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too short');

        IpcFraming::decode('ab'); // Less than 4 bytes
    }

    #[Test]
    public function decodeThrowsOnIncompletePayload(): void
    {
        // Create a frame header claiming 100 bytes but only provide 10
        $frame = pack('N', 100) . 'short';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incomplete');

        IpcFraming::decode($frame);
    }

    #[Test]
    public function decodeThrowsOnOversizedPayload(): void
    {
        // Create a header claiming more than MAX_PAYLOAD_SIZE
        $frame = pack('N', IpcFraming::MAX_PAYLOAD_SIZE + 1) . '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large');

        IpcFraming::decode($frame);
    }

    #[Test]
    public function decodeThrowsOnInvalidJson(): void
    {
        $invalidJson = '{not valid json';
        $frame = pack('N', mb_strlen($invalidJson)) . $invalidJson;

        $this->expectException(JsonException::class);

        IpcFraming::decode($frame);
    }

    #[Test]
    public function extractFromBufferReturnsNullOnInsufficientData(): void
    {
        $buffer = 'ab'; // Less than header size
        $result = IpcFraming::extractFromBuffer($buffer);

        self::assertNull($result);
        self::assertSame('ab', $buffer); // Buffer unchanged
    }

    #[Test]
    public function extractFromBufferReturnsNullOnIncompleteFrame(): void
    {
        $payload = ['test' => true];
        $json = json_encode($payload);
        // Header says full length, but we only provide partial data
        $buffer = pack('N', mb_strlen($json)) . mb_substr($json, 0, 3);

        $result = IpcFraming::extractFromBuffer($buffer);

        self::assertNull($result);
    }

    #[Test]
    public function extractFromBufferConsumesCompleteFrame(): void
    {
        $payload = ['job_id' => 'j1', 'ok' => true, 'result' => 42];
        $frame = IpcFraming::encode($payload);
        $buffer = $frame;

        $result = IpcFraming::extractFromBuffer($buffer);

        self::assertSame($payload, $result);
        self::assertSame('', $buffer); // Buffer fully consumed
    }

    #[Test]
    public function extractFromBufferHandlesMultipleFrames(): void
    {
        $payload1 = ['job_id' => 'j1', 'ok' => true, 'result' => 1];
        $payload2 = ['job_id' => 'j2', 'ok' => true, 'result' => 2];

        $buffer = IpcFraming::encode($payload1) . IpcFraming::encode($payload2);

        $result1 = IpcFraming::extractFromBuffer($buffer);
        self::assertSame($payload1, $result1);
        self::assertNotEmpty($buffer); // Second frame still in buffer

        $result2 = IpcFraming::extractFromBuffer($buffer);
        self::assertSame($payload2, $result2);
        self::assertSame('', $buffer); // Fully consumed
    }

    #[Test]
    public function extractFromBufferHandlesPartialSecondFrame(): void
    {
        $payload1 = ['job_id' => 'j1', 'ok' => true];
        $frame1 = IpcFraming::encode($payload1);

        // Second frame is incomplete (only header + partial payload)
        $payload2Json = json_encode(['job_id' => 'j2']);
        $partialFrame2 = pack('N', mb_strlen($payload2Json)) . mb_substr($payload2Json, 0, 5);

        $buffer = $frame1 . $partialFrame2;

        // First frame extracted successfully
        $result1 = IpcFraming::extractFromBuffer($buffer);
        self::assertSame($payload1, $result1);

        // Second frame incomplete — returns null
        $result2 = IpcFraming::extractFromBuffer($buffer);
        self::assertNull($result2);
        self::assertNotEmpty($buffer); // Partial frame remains
    }

    #[Test]
    public function encodeHandlesUnicodePayload(): void
    {
        $payload = ['message' => 'Héllo wörld 日本語 🎉'];
        $frame = IpcFraming::encode($payload);
        $decoded = IpcFraming::decode($frame);

        self::assertSame($payload, $decoded);
    }

    #[Test]
    public function encodeHandlesEmptyPayload(): void
    {
        $payload = [];
        $frame = IpcFraming::encode($payload);
        $decoded = IpcFraming::decode($frame);

        self::assertSame($payload, $decoded);
    }

    #[Test]
    public function encodeHandlesLargePayload(): void
    {
        // 100KB payload
        $payload = ['data' => str_repeat('x', 100_000)];
        $frame = IpcFraming::encode($payload);
        $decoded = IpcFraming::decode($frame);

        self::assertSame($payload, $decoded);
    }

    #[Test]
    public function binaryPayloadEncodeDecodeRoundTrip(): void
    {
        $binaryData = random_bytes(256);
        $encoded = IpcFraming::encodeBinaryPayload($binaryData);

        self::assertSame('binary', $encoded['type']);
        self::assertSame('base64', $encoded['encoding']);

        $decoded = IpcFraming::decodeBinaryPayload($encoded);
        self::assertSame($binaryData, $decoded);
    }

    #[Test]
    public function decodeBinaryPayloadThrowsOnInvalidType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid binary payload');

        IpcFraming::decodeBinaryPayload(['type' => 'text', 'encoding' => 'utf8', 'data' => 'hello']);
    }

    #[Test]
    public function decodeBinaryPayloadThrowsOnInvalidBase64(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid base64');

        IpcFraming::decodeBinaryPayload(['type' => 'binary', 'encoding' => 'base64', 'data' => '!!!invalid!!!']);
    }

    #[Test]
    public function extractFromBufferThrowsOnOversizedPayload(): void
    {
        $buffer = pack('N', IpcFraming::MAX_PAYLOAD_SIZE + 1) . '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large');

        IpcFraming::extractFromBuffer($buffer);
    }
}
