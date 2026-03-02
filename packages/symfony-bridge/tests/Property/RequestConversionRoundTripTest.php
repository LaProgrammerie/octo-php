<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge\Tests\Property;

use AsyncPlatform\SymfonyBridge\RequestConverter;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Property 1: Request conversion round-trip
 *
 * **Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.9, 6.1**
 *
 * For any OpenSwoole request with arbitrary method, URI, query string, headers,
 * body, cookies, and uploaded files, the conversion via RequestConverter::convert()
 * to an HttpFoundation Request then reading back the fields SHALL produce values
 * equivalent to the original OpenSwoole data.
 */
final class RequestConversionRoundTripTest extends TestCase
{
    use TestTrait;

    private RequestConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new RequestConverter();
    }

    /**
     * Builds a fake OpenSwoole-like request object.
     */
    private function makeSwooleRequest(
        string $method,
        string $uri,
        string $queryString,
        array $headers,
        array $query,
        array $post,
        array $cookies,
        string $body,
    ): object {
        $server = [
            'request_method' => $method,
            'request_uri' => $uri,
            'query_string' => $queryString,
            'server_protocol' => 'HTTP/1.1',
            'server_port' => 8080,
            'remote_addr' => '127.0.0.1',
            'remote_port' => 12345,
            'request_time' => 1700000000,
            'request_time_float' => 1700000000.0,
        ];

        return new class ($server, $headers, $query, $post, $cookies, [], $body) {
            public function __construct(
            public array $server,
            public array $header,
            public array $get,
            public array $post,
            public array $cookie,
            public array $files,
            private string $content,
            ) {}

            public function rawContent(): string
            {
                return $this->content;
            }
        };
    }

    /** @var string[] */
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Generates a safe ASCII string suitable for header values and cookie values.
     * Avoids control characters that would break HTTP semantics.
     */
    private static function safeAsciiString(): \Eris\Generator
    {
        return Generators::map(
            fn(int $len): string => self::randomPrintableAscii($len),
            Generators::choose(1, 50),
        );
    }

    private static function randomPrintableAscii(int $length): string
    {
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= chr(random_int(33, 126)); // printable ASCII, no space
        }
        return $result;
    }

    /**
     * Generates a safe header name (lowercase alpha + hyphens, like OpenSwoole normalizes).
     */
    private static function headerNameGenerator(): \Eris\Generator
    {
        return Generators::map(
            fn(int $len): string => self::randomHeaderName($len),
            Generators::choose(1, 20),
        );
    }

    private static function randomHeaderName(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return 'x-' . $result; // prefix to avoid collisions with standard headers
    }

    /**
     * Generates a safe cookie name (alphanumeric + underscore).
     */
    private static function cookieNameGenerator(): \Eris\Generator
    {
        return Generators::map(
            fn(int $len): string => self::randomCookieName($len),
            Generators::choose(1, 20),
        );
    }

    private static function randomCookieName(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789_';
        $result = 'c'; // prefix to avoid purely numeric names (PHP casts to int keys)
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $result;
    }

    #[Test]
    public function methodIsPreserved(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::elements(self::HTTP_METHODS),
        )->then(function (string $method): void {
            $swoole = $this->makeSwooleRequest(
                method: strtolower($method),
                uri: '/test',
                queryString: '',
                headers: [],
                query: [],
                post: [],
                cookies: [],
                body: '',
            );

            $request = $this->converter->convert($swoole);

            self::assertSame($method, $request->getMethod());
        });
    }

    #[Test]
    public function queryParametersArePreserved(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::choose(1, 5),
        )->then(function (int $paramCount): void {
            $query = [];
            for ($i = 0; $i < $paramCount; $i++) {
                $query['param' . $i] = self::randomPrintableAscii(random_int(1, 20));
            }

            $swoole = $this->makeSwooleRequest(
                method: 'get',
                uri: '/search',
                queryString: http_build_query($query),
                headers: [],
                query: $query,
                post: [],
                cookies: [],
                body: '',
            );

            $request = $this->converter->convert($swoole);

            foreach ($query as $key => $value) {
                self::assertSame($value, $request->query->get($key), "Query param '{$key}' mismatch");
            }
        });
    }

    #[Test]
    public function headersArePreserved(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::choose(1, 5),
        )->then(function (int $headerCount): void {
            $headers = [];
            for ($i = 0; $i < $headerCount; $i++) {
                $headers[self::randomHeaderName(random_int(3, 10))] = self::randomPrintableAscii(random_int(1, 30));
            }

            $swoole = $this->makeSwooleRequest(
                method: 'get',
                uri: '/',
                queryString: '',
                headers: $headers,
                query: [],
                post: [],
                cookies: [],
                body: '',
            );

            $request = $this->converter->convert($swoole);

            foreach ($headers as $name => $value) {
                $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
                self::assertSame($value, $request->server->get($serverKey), "Header '{$name}' not in server vars");
            }
        });
    }

    #[Test]
    public function cookiesArePreserved(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::choose(1, 5),
        )->then(function (int $cookieCount): void {
            $cookies = [];
            for ($i = 0; $i < $cookieCount; $i++) {
                $cookies[self::randomCookieName(random_int(3, 10))] = self::randomPrintableAscii(random_int(1, 30));
            }

            $swoole = $this->makeSwooleRequest(
                method: 'get',
                uri: '/',
                queryString: '',
                headers: [],
                query: [],
                post: [],
                cookies: $cookies,
                body: '',
            );

            $request = $this->converter->convert($swoole);

            foreach ($cookies as $name => $value) {
                self::assertSame($value, $request->cookies->get($name), "Cookie '{$name}' mismatch");
            }
        });
    }

    #[Test]
    public function bodyContentIsPreserved(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::string(),
        )->then(function (string $body): void {
            $swoole = $this->makeSwooleRequest(
                method: 'post',
                uri: '/data',
                queryString: '',
                headers: ['content-type' => 'application/octet-stream'],
                query: [],
                post: [],
                cookies: [],
                body: $body,
            );

            $request = $this->converter->convert($swoole);

            if ($body === '') {
                // Empty body → getContent() returns empty string
                self::assertSame('', $request->getContent());
            } else {
                self::assertSame($body, $request->getContent());
            }
        });
    }

    #[Test]
    public function requestIdIsPropagatedWhenPresent(): void
    {
        $this->limitTo(50);

        $this->forAll(
            self::safeAsciiString(),
        )->then(function (string $requestId): void {
            $swoole = $this->makeSwooleRequest(
                method: 'get',
                uri: '/',
                queryString: '',
                headers: ['x-request-id' => $requestId],
                query: [],
                post: [],
                cookies: [],
                body: '',
            );

            $request = $this->converter->convert($swoole);

            self::assertSame($requestId, $request->attributes->get('_request_id'));
            self::assertSame($requestId, $request->server->get('HTTP_X_REQUEST_ID'));
        });
    }

    #[Test]
    public function serverVarsAreReconstructedCorrectly(): void
    {
        $this->limitTo(50);

        $this->forAll(
            Generators::elements(self::HTTP_METHODS),
            Generators::elements(['/api', '/users/1', '/search', '/a/b/c']),
            Generators::elements(['', 'a=1', 'x=y&z=w']),
        )->then(function (string $method, string $uri, string $qs): void {
            $swoole = $this->makeSwooleRequest(
                method: strtolower($method),
                uri: $uri,
                queryString: $qs,
                headers: [],
                query: [],
                post: [],
                cookies: [],
                body: '',
            );

            $request = $this->converter->convert($swoole);

            self::assertSame($method, $request->server->get('REQUEST_METHOD'));
            self::assertSame($uri, $request->server->get('REQUEST_URI'));
            self::assertSame($qs, $request->server->get('QUERY_STRING'));
            self::assertSame('HTTP/1.1', $request->server->get('SERVER_PROTOCOL'));
            self::assertSame(8080, $request->server->get('SERVER_PORT'));
            self::assertSame('127.0.0.1', $request->server->get('REMOTE_ADDR'));
        });
    }
}
