<?php

declare(strict_types=1);

namespace AsyncPlatform\RuntimePack\Tests\Integration;

/**
 * Trait for managing a real OpenSwoole server process in integration tests.
 *
 * Starts the server as a background process via proc_open(), waits for it to be ready
 * by polling /healthz, and cleans up in tearDown.
 *
 * Requirements:
 * - ext-openswoole must be loaded
 * - The skeleton bin/console must be available
 */
trait ServerProcessTrait
{
    /** @var resource|null */
    private $serverProcess = null;

    /** @var resource[] */
    private array $serverPipes = [];

    private int $serverPort = 0;

    private ?int $serverPid = null;

    private string $serverStderr = '';

    /**
     * Find a random available port.
     */
    protected function findAvailablePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);

        return $port;
    }

    /**
     * Start the server using the skeleton's bin/console.
     *
     * @param string $command 'async:serve' or 'async:run'
     * @param array<string, string> $env Additional environment variables
     * @param string|null $workingDir Working directory (defaults to skeleton/)
     * @return int The port the server is listening on
     */
    protected function startServer(
        string $command = 'async:serve',
        array $env = [],
        ?string $workingDir = null,
    ): int {
        $this->serverPort = $this->findAvailablePort();

        $workingDir ??= $this->getSkeletonDir();

        $defaultEnv = [
            'APP_HOST' => '127.0.0.1',
            'APP_PORT' => (string) $this->serverPort,
            'APP_WORKERS' => '1',
            'SHUTDOWN_TIMEOUT' => '5',
            'REQUEST_HANDLER_TIMEOUT' => '10',
            'MAX_REQUESTS' => '0',
            'MAX_UPTIME' => '0',
            'MAX_MEMORY_RSS' => '0',
            'EVENT_LOOP_LAG_THRESHOLD_MS' => '500',
        ];

        $mergedEnv = array_merge($defaultEnv, $env);

        // Build env string for proc_open
        $envStrings = [];
        foreach ($mergedEnv as $key => $value) {
            $envStrings[$key] = $value;
        }
        // Inherit PATH and other essentials
        foreach (['PATH', 'HOME', 'USER', 'SHELL', 'LANG', 'LC_ALL'] as $inherit) {
            if (isset($_ENV[$inherit])) {
                $envStrings[$inherit] = $_ENV[$inherit];
            } elseif (($val = getenv($inherit)) !== false) {
                $envStrings[$inherit] = $val;
            }
        }

        $phpBinary = PHP_BINARY;
        $consolePath = $workingDir . '/bin/console';

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $this->serverProcess = proc_open(
            [$phpBinary, $consolePath, $command],
            $descriptors,
            $this->serverPipes,
            $workingDir,
            $envStrings,
        );

        if (!is_resource($this->serverProcess)) {
            $this->fail('Failed to start server process');
        }

        // Make stderr non-blocking so we can read logs
        stream_set_blocking($this->serverPipes[2], false);
        stream_set_blocking($this->serverPipes[1], false);

        $status = proc_get_status($this->serverProcess);
        $this->serverPid = $status['pid'];

        return $this->serverPort;
    }

    /**
     * Wait for the server to be ready by polling /healthz.
     *
     * @param float $timeoutSeconds Max time to wait
     * @param float $intervalMs Poll interval in milliseconds
     */
    protected function waitForServerReady(float $timeoutSeconds = 10.0, float $intervalMs = 100): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            // Check if process is still running
            if ($this->serverProcess !== null) {
                $status = proc_get_status($this->serverProcess);
                if (!$status['running']) {
                    $stderr = $this->collectStderr();
                    $this->fail("Server process exited prematurely (exit code: {$status['exitcode']}). Stderr:\n{$stderr}");
                }
            }

            try {
                $response = $this->httpGet('/healthz');
                if ($response['status'] === 200) {
                    return;
                }
            } catch (\Throwable) {
                // Server not ready yet
            }

            usleep((int) ($intervalMs * 1000));
        }

        $stderr = $this->collectStderr();
        $this->fail("Server did not become ready within {$timeoutSeconds}s on port {$this->serverPort}. Stderr:\n{$stderr}");
    }

    /**
     * Make an HTTP GET request to the server.
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    protected function httpGet(string $path, array $headers = [], float $timeout = 5.0): array
    {
        return $this->httpRequest('GET', $path, null, $headers, $timeout);
    }

    /**
     * Make an HTTP POST request to the server.
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    protected function httpPost(string $path, string $body = '', array $headers = [], float $timeout = 5.0): array
    {
        return $this->httpRequest('POST', $path, $body, $headers, $timeout);
    }

    /**
     * Make an HTTP request to the server.
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    protected function httpRequest(
        string $method,
        string $path,
        ?string $body = null,
        array $headers = [],
        float $timeout = 5.0,
    ): array {
        $url = "http://127.0.0.1:{$this->serverPort}{$path}";

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "{$key}: {$value}";
        }

        $opts = [
            'http' => [
                'method' => $method,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headerLines),
            ],
        ];

        if ($body !== null) {
            $opts['http']['content'] = $body;
        }

        $context = stream_context_create($opts);
        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false) {
            throw new \RuntimeException("HTTP request failed: {$method} {$url}");
        }

        // Parse response headers (PHP 8.4+ uses http_get_last_response_headers())
        $statusCode = 0;
        $responseHeaders = [];
        $rawHeaders = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? []);

        if (is_array($rawHeaders)) {
            foreach ($rawHeaders as $header) {
                if (preg_match('#^HTTP/\d+\.?\d*\s+(\d+)#', $header, $m)) {
                    $statusCode = (int) $m[1];
                } elseif (str_contains($header, ':')) {
                    [$key, $value] = explode(':', $header, 2);
                    $responseHeaders[strtolower(trim($key))] = trim($value);
                }
            }
        }

        return [
            'status' => $statusCode,
            'body' => $responseBody,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * Send a signal to the server's master process.
     */
    protected function sendSignal(int $signal): void
    {
        if ($this->serverPid === null) {
            $this->fail('No server PID available');
        }

        // The proc_open PID is the shell PID; we need the actual PHP process.
        // Use negative PID to send to the process group, or find child.
        $childPid = $this->findChildPid($this->serverPid);
        if ($childPid !== null) {
            posix_kill($childPid, $signal);
        } else {
            posix_kill($this->serverPid, $signal);
        }
    }

    /**
     * Find the child PHP process PID (the actual server, not the shell wrapper).
     */
    private function findChildPid(int $parentPid): ?int
    {
        // Try to find child processes via /proc
        $output = [];
        exec("pgrep -P {$parentPid} 2>/dev/null", $output);
        if (!empty($output)) {
            return (int) $output[0];
        }

        return null;
    }

    /**
     * Collect stderr output from the server process.
     */
    protected function collectStderr(): string
    {
        if (!isset($this->serverPipes[2]) || !is_resource($this->serverPipes[2])) {
            return $this->serverStderr;
        }

        while (($line = fgets($this->serverPipes[2])) !== false) {
            $this->serverStderr .= $line;
        }

        return $this->serverStderr;
    }

    /**
     * Collect stdout output from the server process.
     */
    protected function collectStdout(): string
    {
        if (!isset($this->serverPipes[1]) || !is_resource($this->serverPipes[1])) {
            return '';
        }

        $output = '';
        while (($line = fgets($this->serverPipes[1])) !== false) {
            $output .= $line;
        }

        return $output;
    }

    /**
     * Get the path to the skeleton directory.
     */
    protected function getSkeletonDir(): string
    {
        // From packages/runtime-pack/tests/Integration/ → skeleton/
        return dirname(__DIR__, 4) . '/skeleton';
    }

    /**
     * Get the path to the runtime-pack root.
     */
    protected function getRuntimePackDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Stop the server process and clean up.
     */
    protected function stopServer(): void
    {
        if ($this->serverProcess === null) {
            return;
        }

        // Collect remaining stderr
        $this->collectStderr();

        // Try graceful stop first
        $childPid = $this->findChildPid($this->serverPid);
        $targetPid = $childPid ?? $this->serverPid;

        if ($targetPid !== null) {
            @posix_kill($targetPid, SIGTERM);

            // Wait up to 3 seconds for graceful stop
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($this->serverProcess);
                if (!$status['running']) {
                    break;
                }
                usleep(50_000);
            }

            // Force kill if still running
            $status = proc_get_status($this->serverProcess);
            if ($status['running']) {
                @posix_kill($targetPid, SIGKILL);
                usleep(100_000);
            }
        }

        // Close pipes
        foreach ($this->serverPipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        // Close process
        if (is_resource($this->serverProcess)) {
            @proc_close($this->serverProcess);
        }

        $this->serverProcess = null;
        $this->serverPipes = [];
        $this->serverPid = null;
        $this->serverStderr = '';
    }

    /**
     * Wait for the server process to exit.
     *
     * @return int Exit code
     */
    protected function waitForServerExit(float $timeoutSeconds = 10.0): int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->serverProcess);
            if (!$status['running']) {
                return $status['exitcode'];
            }
            usleep(50_000);
        }

        $this->fail("Server did not exit within {$timeoutSeconds}s");
    }

    /**
     * Parse NDJSON log lines from stderr.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseLogLines(?string $stderr = null): array
    {
        $stderr ??= $this->collectStderr();
        $lines = explode("\n", trim($stderr));
        $parsed = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $parsed[] = $decoded;
            }
        }

        return $parsed;
    }

    /**
     * Search log lines for entries matching criteria.
     *
     * @param array<string, mixed> $criteria Key-value pairs to match
     * @return array<int, array<string, mixed>>
     */
    protected function findLogEntries(array $criteria, ?string $stderr = null): array
    {
        $logs = $this->parseLogLines($stderr);
        $matches = [];

        foreach ($logs as $log) {
            $match = true;
            foreach ($criteria as $key => $value) {
                if (!isset($log[$key]) || $log[$key] !== $value) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $matches[] = $log;
            }
        }

        return $matches;
    }
}
