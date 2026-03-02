# ADR-002: Cooperative Yield for CPU Fairness in Event Loop

**Status:** Accepted
**Date:** 2026-03-02
**Context:** Runtime Pack V1 — convoy effect under mixed CPU+IO workloads

## Problem

OpenSwoole coroutines are cooperatively scheduled. A coroutine doing CPU-heavy work
(hashing, JSON processing, image manipulation) monopolizes the event loop, preventing
other coroutines from resuming after IO completion. This causes the "convoy effect":
IO wait times explode under mixed CPU+IO workloads.

Bench evidence (cpu=20000, concurrency=50, io-ms=5):
- IO Wait p50: 1552ms (vs 5ms expected sleep)
- CPU Util: 98% (single-thread saturated)
- Verdict: CPU_BOUND

## Decision

Provide a `CooperativeYield` helper that inserts explicit yield points (`Coroutine::usleep(0)`)
in CPU-heavy loops. This gives the scheduler a chance to resume IO-waiting coroutines
between CPU bursts.

Two APIs:
- Instance-based: `$yield = new CooperativeYield(every: 2000); $yield->tick();`
- Static one-liner: `CooperativeYield::maybeYield($i, 2000);`

## Bench Results (before/after)

Config: jobs=500, concurrency=50, cpu=20000, io-ms=5, json-kb=8

| Metric | No yield | yield-every=5000 | yield-every=2000 |
|--------|----------|-------------------|-------------------|
| IO Wait p50 | 1552ms | 392ms | 163ms |
| IO Wait p95 | 1582ms | 407ms | 169ms |
| CPU p50 | 31ms | 31ms | 31ms |
| Throughput | 31.4 j/s | 31.4 j/s | 31.4 j/s |
| Total time | 15908ms | 15949ms | 15905ms |

Key findings:
- IO fairness improves 9.5x (yield-every=2000) with zero throughput cost
- CPU per-job cost unchanged (yield time excluded from measurement)
- Total wall-clock time unchanged (CPU-bound bottleneck is the same)
- The improvement is in latency distribution, not throughput

## Trade-offs

**Pros:**
- Dramatic IO fairness improvement under CPU pressure
- Zero overhead when disabled (yieldEvery=0)
- Minimal overhead when enabled (~0.1μs per tick, ~5-15μs per actual yield)
- No new dependencies
- Opt-in, not forced

**Cons:**
- Requires developer awareness to insert yield points in CPU loops
- Adds ~5-15μs per yield call (context switch cost)
- Does not increase total throughput (still single-thread CPU-bound)
- yield-every tuning is workload-dependent (no universal default)

## Non-goals

- Auto-detecting CPU-heavy code and yielding automatically (too invasive, unpredictable)
- Multi-process CPU parallelism (separate concern, handled by BlockingPool)
- Preemptive scheduling (not possible in cooperative coroutine model)

## Consequences

- `CooperativeYield` added to runtime-pack as a public utility
- `cooperative_yield_total` metric added to MetricsCollector
- Bench CLI gains `--yield-every=N` option for A/B testing
- WorkerLifecycle event loop lag detection remains the passive guard
- Future: consider auto-yield in ScopeRunner if event loop lag exceeds threshold

## Alternatives Considered

1. **Reduce concurrency** — works but wastes IO overlap potential
2. **BlockingPool for CPU work** — correct for heavy CPU, overkill for light CPU loops
3. **Timer-based preemption** — not possible in PHP/OpenSwoole cooperative model
4. **Auto-yield in framework** — too magic, hard to reason about, breaks determinism
