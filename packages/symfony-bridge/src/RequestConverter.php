<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge;

use const UPLOAD_ERR_OK;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

use function is_array;
use function is_string;

/**
 * Converts an OpenSwoole\Http\Request into a Symfony HttpFoundation\Request.
 *
 * Invariants:
 * - NEVER reads PHP superglobals ($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES)
 * - NEVER modifies PHP superglobals
 * - All data comes exclusively from the OpenSwoole Request object
 * - Multi-valued headers are preserved
 * - UTF-8 URI encoding is preserved
 * - X-Request-Id is propagated into headers AND attributes (_request_id)
 */
final class RequestConverter
{
    /**
     * @param object&\OpenSwoole\Http\Request $swooleRequest OpenSwoole\Http\Request (typed as object for testability with fakes)
     */
    public function convert(object $swooleRequest): Request
    {
        $server = $this->buildServerVars($swooleRequest);
        $headers = $swooleRequest->header;
        $query = $swooleRequest->get ?? [];
        $post = $swooleRequest->post ?? [];
        $cookies = $swooleRequest->cookie ?? [];
        $files = $this->buildFiles($swooleRequest->files ?? []);
        $rawContent = $swooleRequest->rawContent();
        $content = is_string($rawContent) && $rawContent !== '' ? $rawContent : null;

        $request = new Request(
            query: $query,
            request: $post,
            attributes: [],
            cookies: $cookies,
            files: $files,
            server: $server,
            content: $content,
        );

        // Propagate X-Request-Id into attributes
        $requestId = $headers['x-request-id'] ?? null;
        if ($requestId !== null) {
            $request->attributes->set('_request_id', $requestId);
        }

        return $request;
    }

    /**
     * Reconstructs the $_SERVER equivalent from OpenSwoole request data.
     *
     * Maps: REQUEST_METHOD, REQUEST_URI, QUERY_STRING, CONTENT_TYPE, CONTENT_LENGTH,
     * SERVER_PROTOCOL, SERVER_NAME, SERVER_PORT, REMOTE_ADDR, REMOTE_PORT, HTTP_*
     *
     * @param object&\OpenSwoole\Http\Request $swooleRequest
     *
     * @return array<string, mixed>
     */
    private function buildServerVars(object $swooleRequest): array
    {
        $server = $swooleRequest->server;
        $headers = $swooleRequest->header;

        $result = [
            'REQUEST_METHOD' => mb_strtoupper((string) ($server['request_method'] ?? 'GET')),
            'REQUEST_URI' => $server['request_uri'] ?? '/',
            'QUERY_STRING' => $server['query_string'] ?? '',
            'SERVER_PROTOCOL' => $server['server_protocol'] ?? 'HTTP/1.1',
            'SERVER_NAME' => $headers['host'] ?? '0.0.0.0',
            'SERVER_PORT' => (int) ($server['server_port'] ?? 8080),
            'REMOTE_ADDR' => $server['remote_addr'] ?? '127.0.0.1',
            'REMOTE_PORT' => (int) ($server['remote_port'] ?? 0),
            'REQUEST_TIME' => $server['request_time'] ?? time(),
            'REQUEST_TIME_FLOAT' => $server['request_time_float'] ?? microtime(true),
        ];

        // Content-Type and Content-Length (no HTTP_ prefix per CGI convention)
        if (isset($headers['content-type'])) {
            $result['CONTENT_TYPE'] = $headers['content-type'];
        }
        if (isset($headers['content-length'])) {
            $result['CONTENT_LENGTH'] = $headers['content-length'];
        }

        // Map HTTP headers to HTTP_* (CGI convention)
        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . mb_strtoupper(str_replace('-', '_', $name));
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Converts OpenSwoole uploaded files to HttpFoundation UploadedFile objects.
     * Supports multiple files (input name="files[]") via recursive mapping.
     *
     * @param array<string, mixed> $swooleFiles
     *
     * @return array<string, mixed>
     */
    private function buildFiles(array $swooleFiles): array
    {
        $result = [];
        foreach ($swooleFiles as $key => $file) {
            if (is_array($file) && isset($file['tmp_name'])) {
                $result[$key] = $this->buildUploadedFile($file);
            } elseif (is_array($file)) {
                // Multiple files (input name="files[]")
                $result[$key] = $this->buildFiles($file);
            }
        }

        return $result;
    }

    /**
     * @param array{tmp_name: string, name?: string, type?: string, error?: int, size?: int} $fileData
     */
    private function buildUploadedFile(array $fileData): UploadedFile
    {
        return new UploadedFile(
            path: $fileData['tmp_name'],
            originalName: $fileData['name'] ?? '',
            mimeType: $fileData['type'] ?? null,
            error: $fileData['error'] ?? UPLOAD_ERR_OK,
            test: true,
        );
    }
}
