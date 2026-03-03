# Async PHP Platform — Skeleton

A minimal, production-ready async PHP application powered by [OpenSwoole](https://openswoole.com/).

This project was generated via `composer create-project octo-php/skeleton`.

## Quick Start

```bash
# Install dependencies
composer install

# Start in development mode (2 workers, no reload policies)
php bin/console async:serve

# The server is now listening on http://localhost:8080
# GET /         → {"message":"Hello, Async PHP!"}
# GET /healthz  → {"status":"alive"}
# GET /readyz   → {"status":"ready","event_loop_lag_ms":0.0}
```

## Commands

| Command | Mode | Description |
| --- | --- | --- |
| `php bin/console async:serve` | Development | 2 workers, Xdebug tolerated, no reload policies |
| `php bin/console async:run` | Production | Auto workers (`swoole_cpu_num()`), reload policies active, Xdebug forbidden |

## Configuration

All configuration is done via environment variables. Copy `.env.example` to `.env` and adjust values.

The server validates all variables at startup — invalid values prevent the server from starting with an explicit error message.

See [docs/configuration.md](../docs/configuration.md) for the full reference (types, defaults, validation rules, OpenSwoole settings mapping).

### Key Variables

| Variable | Default | Description |
| --- | --- | --- |
| `APP_HOST` | `0.0.0.0` | Bind address |
| `APP_PORT` | `8080` | Bind port |
| `APP_WORKERS` | `0` (auto) | Worker count. `0` = auto-detect |
| `MAX_REQUESTS` | `10000` | Reload worker after N requests (`0` = disabled) |
| `MAX_UPTIME` | `3600` | Reload worker after N seconds (`0` = disabled) |
| `MAX_MEMORY_RSS` | `134217728` | Reload worker at 128 MB RSS (`0` = disabled) |
| `SHUTDOWN_TIMEOUT` | `30` | Graceful shutdown hard timeout (seconds) |
| `REQUEST_HANDLER_TIMEOUT` | `60` | Per-request deadline (seconds) |
| `MAX_CONCURRENT_SCOPES` | `0` | Max concurrent scopes per worker (`0` = unlimited) |
| `EVENT_LOOP_LAG_THRESHOLD_MS` | `500` | Event loop lag threshold for `/readyz` (`0` = disabled) |

## Architecture

This application runs as a **long-running PHP process** powered by OpenSwoole:

```
                    ┌─────────────────────────┐
                    │     Proxy (Caddy/Nginx)  │  ← TLS, compression, static files, HSTS
                    │     anti-slowloris       │
                    └────────────┬────────────┘
                                 │ HTTP (port 8080)
                    ┌────────────▼────────────┐
                    │     Master Process       │  ← Signal handling (SIGTERM/SIGINT)
                    │     (PID 1 in Docker)    │
                    └────────────┬────────────┘
                                 │
              ┌──────────────────┼──────────────────┐
              │                  │                   │
     ┌────────▼────────┐ ┌──────▼───────┐ ┌────────▼────────┐
     │   Worker 0      │ │  Worker 1    │ │  Worker N       │
     │   Event loop    │ │  Event loop  │ │  Event loop     │
     │   + coroutines  │ │  + coroutines│ │  + coroutines   │
     └─────────────────┘ └──────────────┘ └─────────────────┘
```

Each HTTP request runs in a dedicated coroutine provided by OpenSwoole. I/O operations (HTTP calls, file reads, PDO queries with hooks) automatically yield to the event loop — no manual async/await needed.

### Key Concepts

- **Coroutine-per-request**: Each request gets its own coroutine (provided by OpenSwoole, not manually created)
- **Automatic I/O hooks**: `SWOOLE_HOOK_ALL` is enabled at boot — PDO, file I/O, Redis, HTTP clients yield automatically
- **Structured concurrency**: Use `TaskScope::spawn()` for parallel I/O, `joinAll()` to wait (fan-out pattern)
- **Blocking isolation**: CPU-bound or unsafe operations go to `BlockingPool` via named jobs
- **Reload policies**: Workers are automatically restarted based on request count, uptime, or memory usage

## Operational Endpoints

These endpoints are handled internally by the runtime pack — no user code involved.

### GET /healthz — Liveness

Always returns `200` while the process is active (even during shutdown).

```json
{"status": "alive"}
```

Use for Docker `HEALTHCHECK` and Kubernetes liveness probes.

### GET /readyz — Readiness

Returns `200` when the worker is ready to accept traffic:

```json
{"status": "ready", "event_loop_lag_ms": 0.12}
```

Returns `503` when:

| Status | Condition |
| --- | --- |
| `shutting_down` | Graceful shutdown in progress |
| `event_loop_stale` | Event loop tick older than 2 seconds |
| `event_loop_lagging` | Event loop lag exceeds `EVENT_LOOP_LAG_THRESHOLD_MS` |

The `event_loop_lag_ms` field is always included in `200` responses for proactive monitoring.

Use for Kubernetes readiness probes and load balancer health checks.

Both endpoints include `Cache-Control: no-store` and `Content-Type: application/json` headers.


## Proxy Frontal (Required in Production)

The runtime pack is an HTTP application server — **not** a web server. In production, always place a reverse proxy in front:

**Caddy** (recommended for simplicity) or **Nginx** handles:

- **TLS termination** (HTTPS, HSTS, certificate management)
- **HTTP compression** (gzip, brotli)
- **Static file serving** (`public/` directory)
- **Security headers** (CSP, X-Frame-Options, etc.)
- **Anti-slowloris timeouts** — the runtime pack does NOT guarantee read-timeout protection in V1

### Minimal Nginx Configuration (Timeouts)

```nginx
server {
    listen 443 ssl http2;

    # Anti-slowloris: drop slow clients before they reach the app
    client_header_timeout 10s;
    client_body_timeout 10s;
    send_timeout 30s;

    # Proxy to the async PHP app
    location / {
        proxy_pass http://app:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Request-Id $request_id;
        proxy_read_timeout 65s;  # > REQUEST_HANDLER_TIMEOUT (60s)
    }

    # Static files served directly by Nginx
    location /static/ {
        root /app/public;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
```

### Minimal Caddy Configuration

```
{
    servers {
        timeouts {
            read_body   10s
            read_header 10s
            write       65s
            idle        120s
        }
    }
}

example.com {
    reverse_proxy app:8080 {
        header_up X-Request-Id {http.request.uuid}
    }
    file_server /static/* {
        root /app/public
    }
}
```

> **V1 limitation:** The runtime pack does not implement read-timeout at the HTTP parsing level. Slowloris protection relies entirely on the frontal proxy. This is a known non-goal for V1 — see [docs/configuration.md](../docs/configuration.md).

## Writing Async-Safe Handlers

The OpenSwoole runtime is a long-running process with coroutine-based concurrency. Follow these rules to avoid blocking the event loop and leaking state.

### Rule 1: Never Block the Event Loop

All I/O is automatically hooked by OpenSwoole (`SWOOLE_HOOK_ALL`). Standard PHP functions (`file_get_contents`, PDO, Redis) yield to the event loop transparently.

CPU-bound work > 10ms **must** be offloaded to the `BlockingPool` via named jobs.

### Rule 2: Check Cancellation in Long Loops

```php
foreach ($largeDataset as $item) {
    $context->throwIfCancelled();
    // ... process item
}
```

### Rule 3: Use TaskScope for Parallel I/O

Fan-out pattern — spawn N coroutines, `joinAll()` waits for all:

```php
$scope->spawn(fn(RequestContext $ctx) => $userData = fetchUser($id));
$scope->spawn(fn(RequestContext $ctx) => $notifications = fetchNotifications());
$scope->joinAll(); // Waits for both — re-throws first child error (errgroup pattern)
```

### Rule 4: Offload CPU-Bound Work to BlockingPool

Register named jobs in `config/jobs.php`, call them from handlers:

```php
// In handler:
$pdf = $blockingPool->run('pdf.generate', ['report_id' => $id], timeout: 15.0);

// Use runOrRespondError() for standardized HTTP error mapping:
// Full queue → 503 + Retry-After, Timeout → 504, Send failed → 502, Exception → 500
$result = $blockingPool->runOrRespondError('heavy.compute', $payload, $response);
```

### Rule 5: Never Touch the Raw Response

The handler receives a `ResponseFacade` — use `$response->status()`, `$response->header()`, `$response->end()`. The facade guarantees single-response (no double-send).

### Rule 6: PDO/Doctrine — Integration Proof Required

PDO is treated as coroutine-safe **only if** the integration proof passes on the prod image. Otherwise: fallback to `BlockingPool` for all DB operations. Doctrine DBAL requires the same proof plus the reset/reconnect pattern.

### Rule 7: Use IoExecutor for I/O Dependencies

Don't guess whether a library is coroutine-safe. Use `IoExecutor` — it routes automatically based on the `ExecutionPolicy` configured in `config/execution_policy.php`:

```php
$result = $io->run(
    dependency: 'pdo_mysql',
    jobName: 'db.query',
    payload: ['sql' => 'SELECT ...'],
    directCallable: fn() => $pdo->query('SELECT ...')->fetchAll(),
    timeout: 5.0,
);
```

- `DirectCoroutineOk` → runs the callable directly in the coroutine (no overhead)
- `MustOffload` → offloads to BlockingPool (safe default for unknown deps)
- `ProbeRequired` → offloads + logs debug (pending integration proof)

See `config/execution_policy.php` for the default strategy matrix.

#### Default Strategy Matrix

Set automatically at boot by `ExecutionPolicy::defaults($hookFlags)`:

| Dependency | Strategy | Condition |
| --- | --- | --- |
| `openswoole_http` | DirectCoroutineOk | Always (native async) |
| `redis` | DirectCoroutineOk | Always (SWOOLE_HOOK_ALL) |
| `file_io` | DirectCoroutineOk | Always (SWOOLE_HOOK_FILE) |
| `guzzle` | DirectCoroutineOk | If `SWOOLE_HOOK_CURL` active |
| `guzzle` | ProbeRequired | If `SWOOLE_HOOK_CURL` inactive |
| `pdo_mysql` | ProbeRequired | Needs integration proof |
| `pdo_pgsql` | ProbeRequired | Needs integration proof |
| `doctrine_dbal` | ProbeRequired | Needs integration proof |
| `ffi` | MustOffload | Always (blocks event loop) |
| `cpu_bound` | MustOffload | Always (blocks event loop) |
| *(unknown)* | MustOffload | Safe default for unregistered deps |

Override in `config/execution_policy.php`:

```php
return static function (object $policy): void {
    // After integration proof passes on prod image:
    $policy->register('pdo_mysql', \Octo\RuntimePack\ExecutionStrategy::DirectCoroutineOk);
};
```

See [docs/configuration.md](../docs/configuration.md) for the full reference.

## Project Structure

```
├── bin/
│   └── console                  # CLI entry point (async:serve, async:run)
├── config/
│   ├── routes.php               # Application routes
│   ├── jobs.php                 # BlockingPool job registrations (optional)
│   └── execution_policy.php     # ExecutionPolicy configuration (DIRECT/OFFLOAD/PROBE)
├── public/                      # Static files (served by proxy)
├── src/
│   └── Handler/
│       └── HomeHandler.php      # Example: GET / → {"message":"Hello, Async PHP!"}
├── .env.example                 # Environment variables with defaults
├── Dockerfile                   # Multi-stage (dev + prod)
├── docker-compose.yml           # Dev stack
├── composer.json
└── README.md
```

## Docker

```bash
# Development (with Xdebug + Composer)
docker compose up

# Production build
docker build --target prod -t my-app:prod .
docker run -p 8080:8080 my-app:prod
```

The production image:
- Runs as non-root user
- Has OPcache enabled with JIT
- Includes a Docker HEALTHCHECK on `/healthz`
- Does NOT include Xdebug (incompatible with coroutine scheduling)

## License

MIT
