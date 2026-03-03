# Configuration Reference

All configuration is done via environment variables, validated at startup. If any variable is invalid, the server refuses to start and displays an error listing all problematic variables.

## Compatibility Matrix (V1)

| Component | Target Version |
| --- | --- |
| PHP | 8.3+ |
| OpenSwoole | 22.x (latest stable via pecl) |
| OS container | Linux (Debian bookworm-slim) |
| Architecture | amd64, arm64 |
| RSS monitoring | Linux only (`/proc/self/statm`) |

> **Note:** Alpine Linux is not supported. Debian bookworm was chosen for glibc compatibility and OpenSwoole stability. See [ADR-001](adr/001-runtime-pack-decisions.md) for rationale.

## Environment Variables

### Server

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `APP_HOST` | string | `0.0.0.0` | Server bind address. |
| `APP_PORT` | int | `8080` | Server bind port. Must be 1–65535. |
| `APP_WORKERS` | int | `0` (auto) | Number of worker processes. `0` = auto-detect: `swoole_cpu_num()` in prod, `2` in dev. If set explicitly (> 0), that value is used regardless of mode. |

### Security

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `MAX_REQUEST_BODY_SIZE` | int (bytes) | `2097152` (2 MB) | Maximum HTTP request body size. Must be > 0. Maps to OpenSwoole `package_max_length`. |
| `MAX_CONNECTIONS` | int | `1024` | Maximum simultaneous connections. Must be > 0. Maps to OpenSwoole `max_connection`. |
| `REQUEST_HANDLER_TIMEOUT` | int (seconds) | `60` | Applicative timeout for request handlers (ScopeRunner deadline). Must be > 0. Does NOT protect against slow clients (slowloris) — that's handled by the frontal proxy. |

### Graceful Shutdown

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `SHUTDOWN_TIMEOUT` | int (seconds) | `30` | Hard timeout for graceful shutdown. Must be > 0. Should be less than Kubernetes `terminationGracePeriodSeconds`. |

### Reload Policy

Workers are automatically restarted when thresholds are reached, preventing memory leaks and state drift in the long-running process. Set a value to `0` to disable that specific policy.

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `MAX_REQUESTS` | int | `10000` | Max requests per worker before reload. `0` = disabled. Maps to OpenSwoole `max_request`. |
| `MAX_UPTIME` | int (seconds) | `3600` | Max worker uptime before reload. `0` = disabled. Managed applicatively via `exit(0)`. |
| `MAX_MEMORY_RSS` | int (bytes) | `134217728` (128 MB) | Max worker RSS memory before reload. `0` = disabled. Measured via `/proc/self/statm` (Linux only). |
| `WORKER_RESTART_MIN_INTERVAL` | int (seconds) | `5` | Minimum interval between worker restarts. Anti crash-loop guard. See [Anti Crash-Loop Guard](#anti-crash-loop-guard-worker_restart_min_interval) below. |

> **Warning (prod only):** If ALL three reload policies are disabled (`MAX_REQUESTS=0`, `MAX_UPTIME=0`, `MAX_MEMORY_RSS=0`), a warning is emitted at startup. Workers will never be reloaded, risking memory leaks and state drift.

#### Anti Crash-Loop Guard (WORKER_RESTART_MIN_INTERVAL)

When a reload policy triggers (max requests, max uptime, or max memory RSS), the worker sets a `shouldExitAfterCurrentRequest` flag and calls `exit(0)` after the current response is sent. The OpenSwoole manager then forks a new worker.

The `WORKER_RESTART_MIN_INTERVAL` guard prevents rapid restart loops:

- If a worker has been running for **less than N seconds** when a reload policy triggers, the reload is **suppressed** and a warning log is emitted.
- This protects against scenarios where a reload policy fires immediately after worker start (e.g., `MAX_MEMORY_RSS` set too low, or a memory-heavy bootstrap).

The check is performed in `WorkerLifecycle::afterRequest()`. The log includes `worker_id`, `reload_reason`, and the worker's actual uptime.

Default: `5` seconds. Set to `0` to disable the guard (not recommended).

### Blocking Pool

The blocking pool isolates blocking/CPU-bound operations in separate processes, preventing event loop starvation.

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `BLOCKING_POOL_WORKERS` | int | `4` | Number of blocking pool worker processes. `0` = disabled. |
| `BLOCKING_POOL_QUEUE_SIZE` | int | `64` | Outbound queue capacity (bounded channel). Must be >= 1. |
| `BLOCKING_POOL_TIMEOUT` | int (seconds) | `30` | Job execution timeout. Must be >= 1. |

### Concurrency

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `MAX_CONCURRENT_SCOPES` | int | `0` (unlimited) | Max concurrent request scopes per worker. `0` = unlimited. When > 0, a semaphore limits active scopes; excess requests receive 503 immediately. |

### Event Loop Monitoring

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `EVENT_LOOP_LAG_THRESHOLD_MS` | float (ms) | `500.0` | Event loop lag threshold for `/readyz` health check. `0` = disabled. When lag exceeds this threshold, `/readyz` returns 503. |

## Execution Policy (Async Safety Matrix)

The `ExecutionPolicy` determines per-dependency whether an I/O call can run directly in a coroutine (hooked by OpenSwoole) or must be offloaded to the BlockingPool. This is configured once at boot and queried by `IoExecutor` at runtime.

### Strategies

| Strategy | Behavior | When to use |
| --- | --- | --- |
| `DirectCoroutineOk` | Call runs directly in the request coroutine. Zero overhead. | Dependency is coroutine-safe (hooked by OpenSwoole). |
| `MustOffload` | Call is offloaded to BlockingPool (process isolation). | Dependency blocks the event loop (FFI, CPU-bound, unknown legacy). |
| `ProbeRequired` | Offloaded to BlockingPool + debug log emitted. | Dependency may be coroutine-safe but needs integration proof on prod image. |

Unknown/unregistered dependencies default to `MustOffload` (safe fallback).

### Default Matrix

Set automatically by `ExecutionPolicy::defaults($hookFlags)` at boot:

| Dependency | Strategy | Condition |
| --- | --- | --- |
| `openswoole_http` | DirectCoroutineOk | Always (native async) |
| `redis` | DirectCoroutineOk | Always (SWOOLE_HOOK_ALL) |
| `file_io` | DirectCoroutineOk | Always (SWOOLE_HOOK_FILE) |
| `guzzle` | DirectCoroutineOk | If `SWOOLE_HOOK_CURL` active |
| `guzzle` | ProbeRequired | If `SWOOLE_HOOK_CURL` inactive |
| `pdo_mysql` | ProbeRequired | Needs integration proof |
| `pdo_pgsql` | ProbeRequired | Needs integration proof |
| `doctrine_dbal` | ProbeRequired | Needs integration proof + reconnect pattern |
| `ffi` | MustOffload | Always (blocks event loop) |
| `cpu_bound` | MustOffload | Always (blocks event loop) |
| *(unknown)* | MustOffload | Default for unregistered dependencies |

### Overriding Strategies

Override defaults in `config/execution_policy.php`:

```php
return static function (object $policy): void {
    // Promote PDO MySQL after integration proof passes on prod image
    $policy->register('pdo_mysql', \Octo\RuntimePack\ExecutionStrategy::DirectCoroutineOk);

    // Register a custom blocking dependency
    $policy->register('legacy_soap', \Octo\RuntimePack\ExecutionStrategy::MustOffload);
};
```

### IoExecutor Usage

Use `IoExecutor::run()` in handlers — it routes automatically:

```php
$result = $io->run(
    dependency: 'redis',
    jobName: 'cache.get',
    payload: ['key' => 'user:42'],
    directCallable: fn(array $p) => $redis->get($p['key']),
);
// If redis is DirectCoroutineOk → calls $directCallable directly (zero overhead)
// If redis were MustOffload → offloads to BlockingPool via 'cache.get' job
```

If no `directCallable` is provided, the call is always offloaded to BlockingPool regardless of strategy.

## OpenSwoole Settings Mapping

| Environment Variable | OpenSwoole Setting | Notes |
| --- | --- | --- |
| `APP_HOST` | `host` (Server constructor) | Bind address |
| `APP_PORT` | `port` (Server constructor) | Bind port |
| `APP_WORKERS` | `worker_num` | After auto-resolution |
| `MAX_REQUEST_BODY_SIZE` | `package_max_length` | Bytes |
| `MAX_CONNECTIONS` | `max_connection` | Canonical name (>= 4.7) |
| `MAX_REQUESTS` | `max_request` | Native worker reload |
| `REQUEST_HANDLER_TIMEOUT` | N/A | Applicative timer (`Timer::after`) |
| `SHUTDOWN_TIMEOUT` | N/A | Applicative hard timeout |
| `MAX_UPTIME` | N/A | Applicative (`exit(0)` after threshold) |
| `MAX_MEMORY_RSS` | N/A | Applicative (`/proc/self/statm`) |
| `WORKER_RESTART_MIN_INTERVAL` | N/A | Applicative guard |
| `BLOCKING_POOL_WORKERS` | `Process\Pool` workers | Separate process pool |
| `BLOCKING_POOL_QUEUE_SIZE` | Channel capacity | Bounded outbound queue |
| `BLOCKING_POOL_TIMEOUT` | N/A | Job-level timeout |
| `MAX_CONCURRENT_SCOPES` | N/A | Semaphore (Channel tokens) |
| `EVENT_LOOP_LAG_THRESHOLD_MS` | N/A | Lag monitor threshold |

## Event-Loop Lag Monitor

The runtime actively monitors event-loop health using a drift-based measurement. This detects starvation (CPU-bound work blocking the loop) before it becomes critical.

### How It Works

1. A **tick timer fires every 250ms** in each worker (`WorkerLifecycle::tick()`).
2. On each tick, the worker records `lastLoopTickAt` (current timestamp) and computes the **lag** = actual elapsed time since last tick minus the expected 250ms interval.
3. The lag value is stored as `eventLoopLagMs` and exposed via `MetricsCollector`.

### /readyz Integration

The `/readyz` endpoint checks two conditions related to the event loop:

| Condition | Response | Meaning |
| --- | --- | --- |
| `lastLoopTickAt` older than 2 seconds | `503 {"status":"event_loop_stale"}` | Event loop is completely stuck (no ticks at all) |
| `eventLoopLagMs` > `EVENT_LOOP_LAG_THRESHOLD_MS` | `503 {"status":"event_loop_lagging","lag_ms":...}` | Event loop is running but too slow |
| Both OK | `200 {"status":"ready","event_loop_lag_ms":...}` | Healthy — `lag_ms` included for proactive monitoring |

When `/readyz` returns 503, the load balancer / orchestrator stops routing traffic to this worker.

### CPU-Bound Heuristic Warning

If `eventLoopLagMs` exceeds **2× the threshold** AND there are active inflight scopes, the worker emits a warning log:

```json
{"level":"warning","message":"Probable CPU-bound work in handler","worker_id":0,"inflight_scopes":3,"lag_ms":1200.5}
```

This heuristic helps identify handlers that perform CPU-intensive work without offloading to the BlockingPool.

### Configuration

- `EVENT_LOOP_LAG_THRESHOLD_MS=500` (default) — lag above 500ms triggers `/readyz` 503
- `EVENT_LOOP_LAG_THRESHOLD_MS=0` — disables lag-based readiness check (tick stale check remains active)

### Relationship to Readiness

The lag monitor is **per-worker**. Each `/readyz` request is handled by the worker that receives it, reflecting that specific worker's event-loop health. In a multi-worker setup, the orchestrator probes individual workers (or the proxy distributes health checks across them).

The tick stale check (2-second hard threshold) is always active and cannot be disabled — it catches catastrophic loop hangs regardless of the lag threshold setting.

## Graceful Shutdown Behavior

On `SIGTERM`, the server enters a graceful shutdown sequence:

1. **All workers set `shuttingDown = true`** — new requests (except `/healthz` and `/readyz`) receive `503 {"error":"Server shutting down"}`.
2. **`/readyz` returns `503 {"status":"shutting_down"}`** — the load balancer / orchestrator removes the pod from the pool.
3. **`/healthz` continues returning `200`** — the process is still alive.
4. **Active request scopes are cancelled** — `joinAll()` drains child coroutines with a 1-second grace period.
5. **After drain (or `SHUTDOWN_TIMEOUT`)** — `$server->shutdown()` is called.

### Important: Applicative 503, Not Socket-Level Stop Accept

OpenSwoole does **not** support clean socket-level "stop accept". The operational effect is achieved via:

- **Applicative 503 refusal** for non-health routes during shutdown
- **`/readyz` → 503** so the LB/orchestrator stops routing traffic

`$server->shutdown()` is only called **after** the drain completes or the hard timeout expires. This is the standard pattern for OpenSwoole in orchestrated environments (Kubernetes, Docker Swarm).

### Double SIGTERM

A second `SIGTERM` during shutdown triggers **immediate forced stop** — no further drain.

### SIGINT (Dev Mode)

In development mode, `SIGINT` (Ctrl+C) triggers an immediate clean stop without waiting for full drain.

### BlockingPool Shutdown Order

1. Workers drain (cancel scopes, wait inflight requests)
2. BlockingPool stops (wait pending jobs, timeout)
3. Master exits

The BlockingPool lifecycle is master-level: created once at boot, survives worker reloads, stops only after workers are drained.

## Validation Rules

All environment variables are validated at startup. The server refuses to start if any value is invalid, displaying all errors at once.

| Variable | Constraint |
| --- | --- |
| `APP_HOST` | Non-empty string |
| `APP_PORT` | Integer, 1–65535 |
| `APP_WORKERS` | Integer, >= 0 |
| `MAX_REQUEST_BODY_SIZE` | Integer, > 0 |
| `MAX_CONNECTIONS` | Integer, > 0 |
| `REQUEST_HANDLER_TIMEOUT` | Integer, > 0 |
| `SHUTDOWN_TIMEOUT` | Integer, > 0 |
| `MAX_REQUESTS` | Integer, >= 0 |
| `MAX_UPTIME` | Integer, >= 0 |
| `MAX_MEMORY_RSS` | Integer, >= 0 |
| `WORKER_RESTART_MIN_INTERVAL` | Integer, >= 0 |
| `BLOCKING_POOL_WORKERS` | Integer, >= 0 |
| `BLOCKING_POOL_QUEUE_SIZE` | Integer, >= 1 |
| `BLOCKING_POOL_TIMEOUT` | Integer, >= 1 |
| `MAX_CONCURRENT_SCOPES` | Integer, >= 0 |
| `EVENT_LOOP_LAG_THRESHOLD_MS` | Float, >= 0 |
