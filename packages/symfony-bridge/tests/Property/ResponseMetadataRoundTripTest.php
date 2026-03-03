<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Property;

use Octo\SymfonyBridge\ResponseConverter;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Property 3: Response metadata round-trip
 *
 * **Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.7, 3.8**
 *
 * For any HttpFoundation Response with arbitrary status code, headers, cookies, and body,
 * the conversion via ResponseConverter::convert() SHALL produce equivalent metadata
 * on the OpenSwoole side (without Server/X-Powered-By headers).
 */
final class ResponseMetadataRoundTripTest extends TestCase
{
    use TestTrait;

    private ResponseConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new ResponseConverter(new NullLogger());
    }

    /** Valid HTTP status codes for responses. */
    private const STATUS_CODES = [200, 201, 204, 301, 302, 400, 401, 403, 404, 500, 502, 503];

    /**
     * Generates a safe ASCII string for header values (printable, no control chars).
     */
    private static function safeHeaderValue(): \Eris\Generator
    {
        return Generators::map(
            fn(int $len): string => self::randomPrintableAscii(max(1, $len)),
            Generators::choose(1, 40),
        );
    }

    private static function randomPrintableAscii(int $length): string
    {
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            // Printable ASCII 33-126 (no space to avoid trim issues in headers)
            $result .= chr(random_int(33, 126));
        }
        return $result;
    }

    /**
     * Generates a safe header name (lowercase alpha, prefixed with x- to avoid collisions).
     */
    private static function randomHeaderName(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, \strlen($chars) - 1)];
        }
        return 'X-Prop-' . $result;
    }

    #[Test]
    public function statusCodeIsPreserved(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::elements(self::STATUS_CODES),
        )->then(function (int $statusCode): void {
            $response = new Response('', $statusCode);
            $facade = new RecordingFacade();
            $raw = new RecordingRawResponse();

            $this->converter->convert($response, $facade, $raw);

            self::assertSame(
                $statusCode,
                $facade->statusCode,
                "Status code {$statusCode} not preserved",
            );
        });
    }

    #[Test]
    public function headersArePreservedWithoutServerAndXPoweredBy(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::choose(1, 5),
        )->then(function (int $headerCount): void {
            $response = new Response('body');

            // Add random custom headers
            $expectedHeaders = [];
            for ($i = 0; $i < $headerCount; $i++) {
                $name = self::randomHeaderName(random_int(3, 8));
                $value = self::randomPrintableAscii(random_int(1, 20));
                $response->headers->set($name, $value);
                $expectedHeaders[$name] = $value;
            }

            // Add Server and X-Powered-By (should be removed)
            $response->headers->set('Server', 'Apache');
            $response->headers->set('X-Powered-By', 'PHP');

            $facade = new RecordingFacade();
            $raw = new RecordingRawResponse();

            $this->converter->convert($response, $facade, $raw);

            // Verify custom headers are present
            $facadeHeaderMap = [];
            foreach ($facade->headers as [$name, $value]) {
                $facadeHeaderMap[$name] = $value;
            }

            foreach ($expectedHeaders as $name => $value) {
                self::assertArrayHasKey(
                    $name,
                    $facadeHeaderMap,
                    "Header '{$name}' missing from facade",
                );
                self::assertSame($value, $facadeHeaderMap[$name]);
            }

            // Verify Server and X-Powered-By are NOT present
            $headerNames = array_map(fn(array $h) => $h[0], $facade->headers);
            self::assertNotContains('Server', $headerNames);
            self::assertNotContains('X-Powered-By', $headerNames);
        });
    }

    #[Test]
    public function cookiesArePreservedWithAllAttributes(): void
    {
        $this->limitTo(50);

        $sameSiteValues = ['lax', 'strict', 'none'];

        $this->forAll(
            Generators::choose(1, 3),
        )->then(function (int $cookieCount) use ($sameSiteValues): void {
            $response = new Response('body');
            $expectedCookies = [];

            for ($i = 0; $i < $cookieCount; $i++) {
                $name = 'cookie_' . $i;
                $value = self::randomPrintableAscii(random_int(1, 20));
                $secure = (bool) random_int(0, 1);
                $httpOnly = (bool) random_int(0, 1);
                $sameSite = $sameSiteValues[random_int(0, 2)];

                $cookie = Cookie::create($name)
                    ->withValue($value)
                    ->withExpires(1700000000 + $i)
                    ->withPath('/')
                    ->withDomain('.example.com')
                    ->withSecure($secure)
                    ->withHttpOnly($httpOnly)
                    ->withSameSite($sameSite);
                $response->headers->setCookie($cookie);

                $expectedCookies[] = [
                    'name' => $name,
                    'value' => $value,
                    'secure' => $secure,
                    'httpOnly' => $httpOnly,
                    'sameSite' => $sameSite,
                ];
            }

            $facade = new RecordingFacade();
            $raw = new RecordingRawResponse();

            $this->converter->convert($response, $facade, $raw);

            self::assertCount($cookieCount, $raw->cookies, 'Cookie count mismatch');

            foreach ($expectedCookies as $idx => $expected) {
                $actual = $raw->cookies[$idx];
                self::assertSame($expected['name'], $actual['name']);
                self::assertSame($expected['value'], $actual['value']);
                self::assertSame($expected['secure'], $actual['secure']);
                self::assertSame($expected['httpOnly'], $actual['httpOnly']);
                self::assertSame($expected['sameSite'], $actual['sameSite']);
            }
        });
    }

    #[Test]
    public function bodyIsPreserved(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::string(),
        )->then(function (string $body): void {
            $response = new Response($body, 200);
            $facade = new RecordingFacade();
            $raw = new RecordingRawResponse();

            $this->converter->convert($response, $facade, $raw);

            self::assertSame($body, $facade->endContent, 'Body not preserved');
        });
    }

    #[Test]
    public function contentLengthIsPreservedWhenSet(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::choose(0, 10000),
        )->then(function (int $length): void {
            $body = str_repeat('x', $length);
            $response = new Response($body, 200);
            $response->headers->set('Content-Length', (string) $length);

            $facade = new RecordingFacade();
            $raw = new RecordingRawResponse();

            $this->converter->convert($response, $facade, $raw);

            $clHeaders = array_filter(
                $facade->headers,
                fn(array $h) => $h[0] === 'Content-Length',
            );
            self::assertNotEmpty($clHeaders, 'Content-Length header missing');
            $clValue = array_values($clHeaders)[0][1];
            self::assertSame((string) $length, $clValue);
        });
    }
}

/**
 * Recording facade for PBT verification.
 */
class RecordingFacade
{
    public ?int $statusCode = null;
    /** @var list<array{string, string}> */
    public array $headers = [];
    /** @var list<string> */
    public array $writes = [];
    public bool $endCalled = false;
    public string $endContent = '';

    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function header(string $key, string $value): self
    {
        $this->headers[] = [$key, $value];
        return $this;
    }

    public function write(string $content): bool
    {
        $this->writes[] = $content;
        return true;
    }

    public function end(string $content = ''): bool
    {
        $this->endCalled = true;
        $this->endContent = $content;
        return true;
    }

    public function isSent(): bool
    {
        return $this->endCalled;
    }
}

/**
 * Recording raw OpenSwoole response for PBT verification.
 */
class RecordingRawResponse
{
    /** @var list<array{name: string, value: string, expires: int, path: string, domain: string, secure: bool, httpOnly: bool, sameSite: string}> */
    public array $cookies = [];
    public ?string $sentFile = null;
    /** @var list<array{string, string}> */
    public array $headers = [];

    public function cookie(
        string $name,
        string $value,
        int $expires,
        string $path,
        string $domain,
        bool $secure,
        bool $httpOnly,
        string $sameSite,
    ): void {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
        ];
    }

    public function sendfile(string $path): void
    {
        $this->sentFile = $path;
    }

    public function header(string $key, string $value): void
    {
        $this->headers[] = [$key, $value];
    }
}
