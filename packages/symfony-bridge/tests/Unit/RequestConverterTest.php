<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge\Tests\Unit;

use const UPLOAD_ERR_OK;

use Octo\SymfonyBridge\RequestConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(RequestConverter::class)]
final class RequestConverterTest extends TestCase
{
    private RequestConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new RequestConverter();
    }

    // --- Full conversion tests ---

    #[Test]
    public function itConvertsMethodAndUri(): void
    {
        $swoole = $this->makeSwooleRequest([
            'server' => [
                'request_method' => 'post',
                'request_uri' => '/api/users',
                'query_string' => 'page=1&limit=10',
                'server_protocol' => 'HTTP/1.1',
                'server_port' => 443,
                'remote_addr' => '10.0.0.1',
                'remote_port' => 12345,
                'request_time' => 1700000000,
                'request_time_float' => 1700000000.5,
            ],
        ]);

        $request = $this->converter->convert($swoole);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/users', $request->getPathInfo());
        self::assertSame('page=1&limit=10', $request->server->get('QUERY_STRING'));
    }

    #[Test]
    public function itConvertsQueryStringParameters(): void
    {
        $swoole = $this->makeSwooleRequest([
            'get' => ['foo' => 'bar', 'baz' => '42'],
        ]);

        $request = $this->converter->convert($swoole);

        self::assertSame('bar', $request->query->get('foo'));
        self::assertSame('42', $request->query->get('baz'));
    }

    #[Test]
    public function itConvertsSimpleAndMultiValuedHeaders(): void
    {
        $swoole = $this->makeSwooleRequest([
            'header' => [
                'host' => 'example.com',
                'accept' => 'text/html, application/json',
                'x-custom' => 'value1',
            ],
        ]);

        $request = $this->converter->convert($swoole);

        self::assertSame('example.com', $request->headers->get('host'));
        self::assertSame('text/html, application/json', $request->headers->get('accept'));
        self::assertSame('value1', $request->headers->get('x-custom'));
    }

    #[Test]
    public function itConvertsFormDataBody(): void
    {
        $swoole = $this->makeSwooleRequest([
            'server' => [
                'request_method' => 'post',
                'request_uri' => '/submit',
                'query_string' => '',
                'server_protocol' => 'HTTP/1.1',
                'server_port' => 8080,
                'remote_addr' => '127.0.0.1',
                'remote_port' => 0,
                'request_time' => 1700000000,
                'request_time_float' => 1700000000.0,
            ],
            'header' => ['content-type' => 'application/x-www-form-urlencoded'],
            'post' => ['username' => 'john', 'password' => 'secret'],
            'rawContent' => 'username=john&password=secret',
        ]);

        $request = $this->converter->convert($swoole);

        self::assertSame('john', $request->request->get('username'));
        self::assertSame('secret', $request->request->get('password'));
    }

    #[Test]
    public function itConvertsJsonBody(): void
    {
        $json = '{"name":"Alice","age":30}';
        $swoole = $this->makeSwooleRequest([
            'server' => [
                'request_method' => 'post',
                'request_uri' => '/api/data',
                'query_string' => '',
                'server_protocol' => 'HTTP/1.1',
                'server_port' => 8080,
                'remote_addr' => '127.0.0.1',
                'remote_port' => 0,
                'request_time' => 1700000000,
                'request_time_float' => 1700000000.0,
            ],
            'header' => [
                'content-type' => 'application/json',
                'content-length' => (string) mb_strlen($json),
            ],
            'rawContent' => $json,
        ]);

        $request = $this->converter->convert($swoole);

        self::assertSame($json, $request->getContent());
        self::assertSame('application/json', $request->headers->get('content-type'));
    }

    #[Test]
    public function itConvertsCookies(): void
    {
        $swoole = $this->makeSwooleRequest([
            'cookie' => ['session_id' => 'abc123', 'theme' => 'dark'],
        ]);

        $request = $this->converter->convert($swoole);

        self::assertSame('abc123', $request->cookies->get('session_id'));
        self::assertSame('dark', $request->cookies->get('theme'));
    }

    #[Test]
    public function itPropagatesRequestIdInHeadersAndAttributes(): void
    {
        $swoole = $this->makeSwooleRequest([
            'header' => ['x-request-id' => 'req-uuid-42'],
        ]);

        $request = $this->converter->convert($swoole);

        // In attributes
        self::assertSame('req-uuid-42', $request->attributes->get('_request_id'));
        // In server vars (HTTP_X_REQUEST_ID)
        self::assertSame('req-uuid-42', $request->server->get('HTTP_X_REQUEST_ID'));
    }

    #[Test]
    public function itConvertsUploadedFiles(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
        file_put_contents($tmpFile, 'file content');

        $swoole = $this->makeSwooleRequest([
            'files' => [
                'avatar' => [
                    'tmp_name' => $tmpFile,
                    'name' => 'photo.jpg',
                    'type' => 'image/jpeg',
                    'error' => UPLOAD_ERR_OK,
                    'size' => 12,
                ],
            ],
        ]);

        $request = $this->converter->convert($swoole);

        $file = $request->files->get('avatar');
        self::assertInstanceOf(UploadedFile::class, $file);
        self::assertSame('photo.jpg', $file->getClientOriginalName());
        self::assertSame('image/jpeg', $file->getClientMimeType());
        self::assertSame(UPLOAD_ERR_OK, $file->getError());

        @unlink($tmpFile);
    }

    #[Test]
    public function itConvertsMultipleUploadedFiles(): void
    {
        $tmpFile1 = tempnam(sys_get_temp_dir(), 'test_upload_');
        $tmpFile2 = tempnam(sys_get_temp_dir(), 'test_upload_');
        file_put_contents($tmpFile1, 'a');
        file_put_contents($tmpFile2, 'b');

        $swoole = $this->makeSwooleRequest([
            'files' => [
                'docs' => [
                    0 => [
                        'tmp_name' => $tmpFile1,
                        'name' => 'doc1.pdf',
                        'type' => 'application/pdf',
                        'error' => UPLOAD_ERR_OK,
                        'size' => 1,
                    ],
                    1 => [
                        'tmp_name' => $tmpFile2,
                        'name' => 'doc2.pdf',
                        'type' => 'application/pdf',
                        'error' => UPLOAD_ERR_OK,
                        'size' => 1,
                    ],
                ],
            ],
        ]);

        $request = $this->converter->convert($swoole);

        $files = $request->files->get('docs');
        self::assertIsArray($files);
        self::assertCount(2, $files);
        self::assertInstanceOf(UploadedFile::class, $files[0]);
        self::assertInstanceOf(UploadedFile::class, $files[1]);
        self::assertSame('doc1.pdf', $files[0]->getClientOriginalName());
        self::assertSame('doc2.pdf', $files[1]->getClientOriginalName());

        @unlink($tmpFile1);
        @unlink($tmpFile2);
    }

    // --- Server vars reconstruction ---

    #[Test]
    public function itBuildsServerVarsCorrectly(): void
    {
        $swoole = $this->makeSwooleRequest([
            'server' => [
                'request_method' => 'put',
                'request_uri' => '/resource/1',
                'query_string' => 'v=2',
                'server_protocol' => 'HTTP/2',
                'server_port' => 9090,
                'remote_addr' => '192.168.1.1',
                'remote_port' => 55555,
                'request_time' => 1700000000,
                'request_time_float' => 1700000000.0,
            ],
            'header' => [
                'host' => 'api.example.com',
                'content-type' => 'application/json',
                'content-length' => '42',
            ],
        ]);

        $request = $this->converter->convert($swoole);

        self::assertSame('PUT', $request->server->get('REQUEST_METHOD'));
        self::assertSame('/resource/1', $request->server->get('REQUEST_URI'));
        self::assertSame('v=2', $request->server->get('QUERY_STRING'));
        self::assertSame('HTTP/2', $request->server->get('SERVER_PROTOCOL'));
        self::assertSame('api.example.com', $request->server->get('SERVER_NAME'));
        self::assertSame(9090, $request->server->get('SERVER_PORT'));
        self::assertSame('192.168.1.1', $request->server->get('REMOTE_ADDR'));
        self::assertSame(55555, $request->server->get('REMOTE_PORT'));
        self::assertSame('application/json', $request->server->get('CONTENT_TYPE'));
        self::assertSame('42', $request->server->get('CONTENT_LENGTH'));
        // HTTP_ prefixed headers
        self::assertSame('api.example.com', $request->server->get('HTTP_HOST'));
    }

    // --- Edge cases ---

    #[Test]
    public function itHandlesEmptyHeaders(): void
    {
        $swoole = $this->makeSwooleRequest(['header' => []]);

        $request = $this->converter->convert($swoole);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/', $request->getPathInfo());
    }

    #[Test]
    public function itHandlesEmptyBody(): void
    {
        $swoole = $this->makeSwooleRequest(['rawContent' => '']);

        $request = $this->converter->convert($swoole);

        self::assertSame('', $request->getContent());
    }

    #[Test]
    public function itHandlesZeroSizeFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
        // Leave file empty (0 bytes)

        $swoole = $this->makeSwooleRequest([
            'files' => [
                'empty' => [
                    'tmp_name' => $tmpFile,
                    'name' => 'empty.txt',
                    'type' => 'text/plain',
                    'error' => UPLOAD_ERR_OK,
                    'size' => 0,
                ],
            ],
        ]);

        $request = $this->converter->convert($swoole);

        $file = $request->files->get('empty');
        self::assertInstanceOf(UploadedFile::class, $file);
        self::assertSame('empty.txt', $file->getClientOriginalName());
        self::assertSame(0, $file->getSize());

        @unlink($tmpFile);
    }

    #[Test]
    public function itHandlesCookiesWithSpecialCharacters(): void
    {
        $swoole = $this->makeSwooleRequest([
            'cookie' => [
                'data' => 'value=with=equals&and+plus',
                'utf8' => 'café',
            ],
        ]);

        $request = $this->converter->convert($swoole);

        self::assertSame('value=with=equals&and+plus', $request->cookies->get('data'));
        self::assertSame('café', $request->cookies->get('utf8'));
    }

    #[Test]
    public function itHandlesUtf8AndPercentEncodedUri(): void
    {
        $swoole = $this->makeSwooleRequest([
            'server' => [
                'request_method' => 'get',
                'request_uri' => '/search/%E4%B8%AD%E6%96%87',
                'query_string' => 'q=%C3%A9l%C3%A8ve',
                'server_protocol' => 'HTTP/1.1',
                'server_port' => 8080,
                'remote_addr' => '127.0.0.1',
                'remote_port' => 0,
                'request_time' => 1700000000,
                'request_time_float' => 1700000000.0,
            ],
            'get' => ['q' => 'élève'],
        ]);

        $request = $this->converter->convert($swoole);

        self::assertSame('/search/%E4%B8%AD%E6%96%87', $request->server->get('REQUEST_URI'));
        self::assertSame('q=%C3%A9l%C3%A8ve', $request->server->get('QUERY_STRING'));
        // OpenSwoole decodes query params — the decoded value is in $request->get
        self::assertSame('élève', $request->query->get('q'));
    }

    #[Test]
    public function itHandlesRequestWithoutContentType(): void
    {
        $swoole = $this->makeSwooleRequest([
            'header' => ['host' => 'example.com'],
        ]);

        $request = $this->converter->convert($swoole);

        self::assertNull($request->server->get('CONTENT_TYPE'));
        self::assertNull($request->server->get('CONTENT_LENGTH'));
    }

    #[Test]
    public function itDoesNotSetRequestIdAttributeWhenHeaderAbsent(): void
    {
        $swoole = $this->makeSwooleRequest(['header' => []]);

        $request = $this->converter->convert($swoole);

        self::assertFalse($request->attributes->has('_request_id'));
    }

    // --- Superglobals invariant ---

    #[Test]
    public function itDoesNotReadOrModifySuperglobals(): void
    {
        // Snapshot superglobals before conversion
        $serverBefore = $_SERVER;
        $getBefore = $_GET;
        $postBefore = $_POST;
        $cookieBefore = $_COOKIE;
        $filesBefore = $_FILES;

        $swoole = $this->makeSwooleRequest([
            'server' => [
                'request_method' => 'post',
                'request_uri' => '/test',
                'query_string' => 'a=1',
                'server_protocol' => 'HTTP/1.1',
                'server_port' => 8080,
                'remote_addr' => '10.0.0.1',
                'remote_port' => 9999,
                'request_time' => 1700000000,
                'request_time_float' => 1700000000.0,
            ],
            'header' => ['content-type' => 'application/json', 'x-request-id' => 'test-id'],
            'get' => ['a' => '1'],
            'post' => ['b' => '2'],
            'cookie' => ['c' => '3'],
            'rawContent' => '{"test":true}',
        ]);

        $this->converter->convert($swoole);

        // Verify superglobals are unchanged
        self::assertSame($serverBefore, $_SERVER);
        self::assertSame($getBefore, $_GET);
        self::assertSame($postBefore, $_POST);
        self::assertSame($cookieBefore, $_COOKIE);
        self::assertSame($filesBefore, $_FILES);
    }

    // --- Helper ---

    /**
     * Builds a fake OpenSwoole-like request object (anonymous class with the expected shape).
     *
     * @param array<string, mixed> $overrides
     */
    private function makeSwooleRequest(array $overrides = []): object
    {
        $server = $overrides['server'] ?? [
            'request_method' => 'GET',
            'request_uri' => '/',
            'query_string' => '',
            'server_protocol' => 'HTTP/1.1',
            'server_port' => 8080,
            'remote_addr' => '127.0.0.1',
            'remote_port' => 54321,
            'request_time' => 1700000000,
            'request_time_float' => 1700000000.123,
        ];
        $header = $overrides['header'] ?? [];
        $get = $overrides['get'] ?? [];
        $post = $overrides['post'] ?? [];
        $cookie = $overrides['cookie'] ?? [];
        $files = $overrides['files'] ?? [];
        $content = $overrides['rawContent'] ?? '';

        return new class($server, $header, $get, $post, $cookie, $files, $content) {
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
}
