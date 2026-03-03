# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Monorepo note:** Each tag `vX.Y.Z` is applied globally and propagated to all
> split packages (runtime-pack, symfony-bridge, symfony-bundle, etc.) via the
> [split workflow](.github/workflows/split.yml). There are no per-package versions.

## [Unreleased]

### Added

### Changed

### Deprecated

### Removed

### Fixed

### Security

## [0.1.0] - 2025-06-01

### Added

- Runtime Pack V1: OpenSwoole coroutine-per-request server with structured concurrency
- BlockingPool for legacy/CPU-bound isolation (IPC framing, bounded queue, timeouts)
- ExecutionPolicy (DIRECT / OFFLOAD / PROBE) + IoExecutor
- Healthchecks `/healthz` and `/readyz` with event-loop lag monitor
- Graceful shutdown with scope drain + hard timeout
- Reload policies (max_requests, max_uptime, max_memory_rss)
- JSON logging (PSR-3 NDJSON), Request ID middleware (UUIDv4)
- MetricsCollector (counters, histograms, gauges, lag, scope_rejected)
- ServerConfig with env-var validation at startup
- Symfony Bridge package (HttpKernel adapter)
- Docker multi-stage image (dev + prod)
- Monorepo split workflow (GitHub Actions + splitsh-lite)
- Benchmark suite

[Unreleased]: https://github.com/LaProgrammerie/octo-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/LaProgrammerie/octo-php/releases/tag/v0.1.0
