# ADR-001: Runtime Pack OpenSwoole — Key Architectural Decisions

**Status:** Accepted
**Date:** 2025-01-01
**Context:** Runtime Pack V1 (OpenSwoole HTTP server, skeleton, prod-safe policies)

## 1. Debian Bookworm over Alpine

**Decision:** Use `php:8.3-cli-bookworm` (Debian slim) as the base Docker image.

**Context:** Alpine uses musl libc. OpenSwoole is compiled against glibc and some extensions (notably `ext-openswoole` via pecl) have known stability issues on musl.

**Consequences:**
- Larger image (~150 MB vs ~50 MB), acceptable for a long-running server
- Native glibc compatibility — no surprises with OpenSwoole, PDO drivers, or FFI
- `/proc/self/statm` available for RSS monitoring (Linux-specific)

**Rejected:** Alpine — glibc shim (`gcompat`) adds complexity and risk for no meaningful gain in a server context.

## 2. UUIDv4 for Request IDs

**Decision:** Generate request IDs as UUIDv4 via `random_bytes(16)` with RFC 4122 bit manipulation.

**Context:** Needed a unique, human-readable request identifier propagated via `X-Request-Id`.

**Alternatives considered:**
- ULID: Sortable, but adds a dependency or non-trivial implementation. Sortability is not needed for request correlation.
- Uniqid: Not cryptographically random, collision-prone under concurrency.

**Consequences:**
- Zero external dependencies
- Universally recognized format (compatible with all logging/tracing systems)
- No ordering guarantee (not needed — traces handle causality)

## 3. exit(0) for Worker Reload

**Decision:** Workers call `exit(0)` to trigger reload when a reload policy threshold is reached (max uptime, max memory RSS).

**Context:** OpenSwoole's manager process monitors workers. When a worker exits, the manager forks a replacement. `max_request` is handled natively by OpenSwoole, but `max_uptime` and `max_memory_rss` are applicative.

**Mechanism:**
1. `WorkerLifecycle::afterRequest()` checks reload policies
2. If triggered, sets `shouldExitAfterCurrentRequest = true`
3. After `response->end()`, the worker calls `exit(0)`
4. The manager forks a new worker (native OpenSwoole behavior)

**Consequences:**
- Simple, reliable, uses native OpenSwoole lifecycle
- No custom IPC needed for reload signaling
- The `WORKER_RESTART_MIN_INTERVAL` guard prevents crash-loops

**Rejected:** `$server->reload()` — reloads ALL workers simultaneously, not suitable for per-worker policy triggers.

## 4. Channel-Based Semaphore for Concurrency Limiting

**Decision:** Use an OpenSwoole `Channel` pre-filled with tokens as a semaphore to limit concurrent request scopes per worker.

**Mechanism:**
- Channel created with capacity = `MAX_CONCURRENT_SCOPES`, pre-filled with N tokens
- `pop(0)` = non-blocking acquire. If empty → 503 immediate (no queuing)
- `push(true)` = release (in `finally` block)

**Consequences:**
- O(1) acquire/release, non-blocking
- Native OpenSwoole primitive — no external dependency
- Excess requests get 503 + `Retry-After: 1` immediately (fail-fast, no head-of-line blocking)
- `MAX_CONCURRENT_SCOPES=0` disables the semaphore (unlimited)

**Rejected:** Mutex/lock-based approaches — blocking, risk of deadlock in coroutine context.

## 5. ExecutionPolicy — Static Configuration per Dependency

**Decision:** Each I/O dependency has a statically configured execution strategy: `DirectCoroutineOk`, `MustOffload`, or `ProbeRequired`.

**Context:** In a coroutine runtime, some libraries are hooked by OpenSwoole (safe to call directly), others block the event loop. Developers shouldn't have to guess.

**Mechanism:**
- `ExecutionPolicy::defaults($hookFlags)` sets the matrix at boot based on active hooks
- `IoExecutor::run()` routes calls automatically based on the policy
- Unknown dependencies default to `MustOffload` (safe fallback)

**Consequences:**
- Centralized, auditable — one place to see all I/O safety decisions
- Reduces human error (no "I forgot to offload this PDO call")
- Extensible: `config/execution_policy.php` allows per-project overrides
- Auto-probe at boot planned for V1.5

**Rejected:** Runtime auto-detection — too complex for V1, risk of false positives.

## 6. IPC Framing with uint32 Length Prefix

**Decision:** BlockingPool IPC uses a binary framing protocol: 4-byte big-endian length prefix followed by JSON payload.

**Context:** OpenSwoole process IPC can fragment messages. Without framing, large payloads arrive as partial reads.

**Mechanism:**
- Writer: `pack('N', strlen($json)) . $json`
- Reader: Read 4 bytes → extract length → read exactly N bytes → `json_decode`
- Binary payloads use base64 encoding within the JSON structure

**Consequences:**
- Prevents message fragmentation regardless of payload size
- Deterministic parsing — no delimiter-based splitting
- Tested with payloads > 64KB

**Rejected:** Newline-delimited JSON — fragile with payloads containing newlines, no length guarantee.

## 7. ResponseFacade / ResponseState Pattern

**Decision:** Handlers never receive the raw OpenSwoole `Response`. They interact with a `ResponseFacade` backed by a `ResponseState` that enforces single-response semantics.

**Mechanism:**
- `ResponseFacade` wraps `OpenSwoole\Http\Response`
- `ResponseState` tracks: `sent` (bool), `statusCode` (int), `hasExplicitStatusCode` (bool)
- `end()` is idempotent — second call returns `false` + warning log
- `status()` / `header()` after `end()` are silently ignored
- If no explicit `status()` was called before `end()`, statusCode defaults to 200

**Consequences:**
- No double-send bugs (common in async handlers with error paths)
- Access log always has a reliable `statusCode` (from `ResponseState`)
- Timeout handler (408) and exception handler (500) can safely call `trySendError()` without checking if a response was already sent

## 8. NDJSON Logging on stderr

**Decision:** All logs are emitted as Newline-Delimited JSON (NDJSON) on stderr.

**Context:** Long-running server needs structured, machine-parseable logs compatible with standard ingestion pipelines.

**Format:**
```json
{"timestamp":"2025-01-01T00:00:00.000Z","level":"info","message":"...","component":"runtime","request_id":null,"extra":{}}
```

**Consequences:**
- PSR-3 compatible (`JsonLogger` implements `Psr\Log\LoggerInterface`)
- One line per log entry — compatible with ELK, Loki, CloudWatch, Datadog
- stderr avoids buffering issues with stdout in Docker
- `component` field enables filtering (runtime, http, blocking_pool, etc.)
- `request_id` enables end-to-end request correlation
