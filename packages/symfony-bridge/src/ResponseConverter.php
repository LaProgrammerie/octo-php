<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Converts an HttpFoundation Response into an OpenSwoole response via ResponseFacade.
 *
 * Handles 4 cases:
 * 1. Standard Response → status + headers + cookies + end($body)
 * 2. StreamedResponse → status + headers + write() per chunk + end('')
 * 3. BinaryFileResponse → status + headers + sendfile()
 * 4. SSE (StreamedResponse + text/event-stream) → disable buffering + immediate write()
 *
 * Invariants:
 * - Server and X-Powered-By headers are removed
 * - Cookies are mapped with all attributes (secure, httpOnly, sameSite)
 * - Multi-valued headers are preserved (e.g. Set-Cookie)
 * - Content-Length is preserved if present
 * - In SSE mode, HTTP compression and buffering are disabled
 */
final class ResponseConverter
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param Response $sfResponse       Symfony response
     * @param object   $facade           ResponseFacade from runtime pack (status/header/write/end)
     * @param object   $rawSwooleResponse OpenSwoole\Http\Response (for sendfile/cookie)
     */
    public function convert(
        Response $sfResponse,
        object $facade,
        object $rawSwooleResponse,
    ): void {
        // Remove sensitive headers before any conversion
        $sfResponse->headers->remove('Server');
        $sfResponse->headers->remove('X-Powered-By');

        if ($sfResponse instanceof BinaryFileResponse) {
            $this->convertBinaryFile($sfResponse, $facade, $rawSwooleResponse);
        } elseif ($sfResponse instanceof StreamedJsonResponse || $sfResponse instanceof StreamedResponse) {
            $this->convertStreamed($sfResponse, $facade, $rawSwooleResponse);
        } else {
            $this->convertStandard($sfResponse, $facade, $rawSwooleResponse);
        }
    }

    /**
     * Standard response: status + headers + cookies + end($body).
     */
    private function convertStandard(
        Response $sfResponse,
        object $facade,
        object $rawSwooleResponse,
    ): void {
        $facade->status($sfResponse->getStatusCode());
        $this->writeHeaders($sfResponse, $facade);
        $this->writeCookies($sfResponse, $rawSwooleResponse);
        $facade->end($sfResponse->getContent() ?: '');
    }

    /**
     * Streamed response: intercept callback output via ob_start() redirected to write().
     *
     * For SSE (Content-Type: text/event-stream), disables compression and buffering
     * before starting the stream.
     *
     * If the callback throws, logs the error and terminates the response.
     */
    private function convertStreamed(
        StreamedResponse $sfResponse,
        object $facade,
        object $rawSwooleResponse,
    ): void {
        $facade->status($sfResponse->getStatusCode());
        $this->writeHeaders($sfResponse, $facade);
        $this->writeCookies($sfResponse, $rawSwooleResponse);

        // SSE: disable compression and buffering
        if ($this->isSSE($sfResponse)) {
            $this->disableBuffering($rawSwooleResponse);
        }

        // Intercept callback output via ob_start and redirect to ResponseFacade::write()
        try {
            ob_start(function (string $chunk) use ($facade): string {
                if ($chunk !== '') {
                    $facade->write($chunk);
                }
                return '';
            }, 1); // chunk_size=1 for immediate flush

            $sfResponse->sendContent();

            ob_end_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $this->logger->error('StreamedResponse callback exception', [
                'error' => $e->getMessage(),
                'exception_class' => \get_class($e),
            ]);
        }

        $facade->end('');
    }

    /**
     * Binary file response: use sendfile() for optimal performance.
     */
    private function convertBinaryFile(
        BinaryFileResponse $sfResponse,
        object $facade,
        object $rawSwooleResponse,
    ): void {
        $facade->status($sfResponse->getStatusCode());
        $this->writeHeaders($sfResponse, $facade);
        $this->writeCookies($sfResponse, $rawSwooleResponse);

        $file = $sfResponse->getFile();
        $rawSwooleResponse->sendfile($file->getPathname());
    }

    /**
     * Writes all headers (except cookies) from the Symfony response to the facade.
     * Multi-valued headers are preserved by iterating over all values.
     */
    private function writeHeaders(Response $sfResponse, object $facade): void
    {
        foreach ($sfResponse->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ($values as $value) {
                $facade->header($name, $value);
            }
        }
    }

    /**
     * Maps HttpFoundation cookies to the raw OpenSwoole response via cookie().
     * Preserves all attributes: name, value, expiration, path, domain, secure, httpOnly, sameSite.
     */
    private function writeCookies(Response $sfResponse, object $rawSwooleResponse): void
    {
        foreach ($sfResponse->headers->getCookies() as $cookie) {
            $rawSwooleResponse->cookie(
                $cookie->getName(),
                $cookie->getValue() ?? '',
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain() ?? '',
                $cookie->isSecure(),
                $cookie->isHttpOnly(),
                $cookie->getSameSite() ?? '',
            );
        }
    }

    /**
     * Detects SSE responses by Content-Type header.
     */
    private function isSSE(Response $sfResponse): bool
    {
        return str_contains(
            $sfResponse->headers->get('Content-Type', ''),
            'text/event-stream',
        );
    }

    /**
     * Disables HTTP compression and buffering for SSE.
     * Sets X-Accel-Buffering: no and Cache-Control: no-cache on the raw response.
     */
    private function disableBuffering(object $rawSwooleResponse): void
    {
        $rawSwooleResponse->header('X-Accel-Buffering', 'no');
        $rawSwooleResponse->header('Cache-Control', 'no-cache');
    }
}
