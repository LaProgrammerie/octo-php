<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Property;

use Octo\SymfonyBridge\RequestConverter;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Property 2: Superglobals invariant
 *
 * **Validates: Requirements 2.10, 2.11**
 *
 * For any OpenSwoole request processed by the bridge, the PHP superglobals
 * ($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES) SHALL remain unchanged before
 * and after the conversion. The bridge neither reads nor modifies superglobals.
 */
final class SuperglobalsInvariantTest extends TestCase
{
    use TestTrait;

    private RequestConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new RequestConverter();
    }

    /** @var string[] */
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Builds a fake OpenSwoole-like request object with random data.
     */
    private function makeSwooleRequest(
        string $method,
        string $uri,
        array $headers,
        array $query,
        array $post,
        array $cookies,
        string $body,
    ): object {
        $server = [
            'request_method' => $method,
            'request_uri' => $uri,
            'query_string' => http_build_query($query),
            'server_protocol' => 'HTTP/1.1',
            'server_port' => 8080,
            'remote_addr' => '10.0.0.1',
            'remote_port' => 54321,
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

    private static function randomPrintableAscii(int $length): string
    {
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= chr(random_int(33, 126));
        }
        return $result;
    }

    #[Test]
    public function superglobalsAreUnchangedAfterConversion(): void
    {
        $this->limitTo(100);

        $this->forAll(
            Generators::elements(self::HTTP_METHODS),
            Generators::elements(['/api', '/users', '/test', '/']),
            Generators::choose(0, 3),
            Generators::choose(0, 3),
            Generators::choose(0, 3),
        )->then(function (string $method, string $uri, int $queryCount, int $postCount, int $cookieCount, ): void {
            // Build random data
            $query = [];
            for ($i = 0; $i < $queryCount; $i++) {
                $query['q' . $i] = self::randomPrintableAscii(random_int(1, 10));
            }
            $post = [];
            for ($i = 0; $i < $postCount; $i++) {
                $post['p' . $i] = self::randomPrintableAscii(random_int(1, 10));
            }
            $cookies = [];
            for ($i = 0; $i < $cookieCount; $i++) {
                $cookies['c' . $i] = self::randomPrintableAscii(random_int(1, 10));
            }
            $headers = [
                'host' => 'example.com',
                'content-type' => 'application/json',
                'x-request-id' => self::randomPrintableAscii(16),
            ];
            $body = self::randomPrintableAscii(random_int(0, 50));

            // Snapshot superglobals BEFORE conversion
            $serverBefore = $_SERVER;
            $getBefore = $_GET;
            $postBefore = $_POST;
            $cookieBefore = $_COOKIE;
            $filesBefore = $_FILES;

            $swoole = $this->makeSwooleRequest(
                method: strtolower($method),
                uri: $uri,
                headers: $headers,
                query: $query,
                post: $post,
                cookies: $cookies,
                body: $body,
            );

            // Execute conversion
            $this->converter->convert($swoole);

            // Verify superglobals are UNCHANGED
            self::assertSame($serverBefore, $_SERVER, '$_SERVER was modified');
            self::assertSame($getBefore, $_GET, '$_GET was modified');
            self::assertSame($postBefore, $_POST, '$_POST was modified');
            self::assertSame($cookieBefore, $_COOKIE, '$_COOKIE was modified');
            self::assertSame($filesBefore, $_FILES, '$_FILES was modified');
        });
    }
}
