# Async PHP Platform — OpenSwoole (opinionated)

Plateforme async PHP opinionated, basée sur **OpenSwoole**, qui fournit un runtime pack prod-ready et un skeleton (create-project) pour démarrer en quelques secondes.

Ce n'est pas "un framework async de plus". C'est un runtime qui garantit : latence maîtrisée, QoS, observabilité, garde-fous.

## Quick Start

```bash
composer create-project octo-php/skeleton mon-projet
cd mon-projet

# Mode développement (2 workers, pas de reload policies)
php bin/console async:serve

# Mode production (auto workers, reload policies actives)
php bin/console async:run
```

Le serveur écoute sur `http://localhost:8080` :

- `GET /` → `{"message":"Hello, Async PHP!"}`
- `GET /healthz` → `{"status":"alive"}`
- `GET /readyz` → `{"status":"ready","event_loop_lag_ms":0.0}`

### Docker

```bash
# Dev
docker compose up

# Prod
docker build --target prod -t my-app:prod .
docker run -p 8080:8080 my-app:prod
```

## Ce que fournit le Runtime Pack V1

- Serveur HTTP OpenSwoole coroutine-per-request (`SWOOLE_HOOK_ALL`)
- Structured concurrency : RequestContext + TaskScope + ScopeRunner (synchrone yield-ok)
- BlockingPool pour isolation des opérations bloquantes (IPC framé, jobs nommés, corrélation par job_id)
- ExecutionPolicy centralisée (DIRECT / OFFLOAD / PROBE) + IoExecutor
- Sémaphore de concurrence par worker (maxConcurrentScopes, Channel borné)
- ResponseFacade pour garantie single-response (le handler ne touche jamais la Response brute)
- Healthchecks (`/healthz`, `/readyz`) avec event-loop lag monitor actif
- Graceful shutdown avec drain des scopes actifs + timeout hard
- Reload policies (max_requests, max_uptime, max_memory_rss) + garde-fou anti crash-loop
- Logging NDJSON (PSR-3), Request ID (UUIDv4), sécurité de base
- Métriques internes (compteurs, histogramme, gauges, event_loop_lag_ms, scope_rejected)
- Startup checks (hooks, Xdebug, flags, curl hook status)
- Image Docker multi-stage (dev + prod)

### Non-goals V1

- OTEL exporter / endpoint `/metrics` (spec Observability V1)
- SSE / WebSocket streaming
- Limites de concurrence par route/tenant
- Channel&lt;T&gt; pour backpressure
- CLI avancé (async:doctor, async:bench)
- Support FrankenPHP
- Drivers DB async natifs

## Architecture

### Modèle de processus

```text
                    ┌─────────────────────────┐
                    │     Proxy (Caddy/Nginx)  │  ← TLS, compression, static, HSTS
                    └────────────┬────────────┘
                                 │ HTTP (port 8080)
                    ┌────────────▼────────────┐
                    │     Master Process       │  ← Signaux (SIGTERM/SIGINT)
                    │     + BlockingPool       │     Pool lifecycle = master-level
                    └────────────┬────────────┘
                                 │
              ┌──────────────────┼──────────────────┐
              │                  │                   │
     ┌────────▼────────┐ ┌──────▼───────┐ ┌────────▼────────┐
     │   Worker 0      │ │  Worker 1    │ │  Worker N       │
     │   Event loop    │ │  Event loop  │ │  Event loop     │
     │   + coroutines  │ │  + coroutines│ │  + coroutines   │
     │   + ScopeRunner │ │  + reader IPC│ │  + lag monitor  │
     └─────────────────┘ └──────────────┘ └─────────────────┘
```

Chaque requête HTTP s'exécute dans une coroutine dédiée fournie par OpenSwoole. Les I/O yield automatiquement à l'event loop grâce aux hooks (`SWOOLE_HOOK_ALL`).

### Composants principaux

| Composant | Rôle |
| --- | --- |
| `ServerBootstrap` | Point d'entrée, crée et configure le serveur, startup checks |
| `ServerConfig` / `ServerConfigFactory` | DTO readonly + validation des env vars au démarrage |
| `RequestHandler` | Routing interne (/healthz, /readyz, app routes) + dispatch |
| `ScopeRunner` | Orchestre handler + deadline + sémaphore concurrence |
| `RequestContext` | Deadline + cancellation coopérative par requête |
| `TaskScope` | Structured concurrency (spawn/joinAll, fail-fast, errgroup) |
| `ResponseFacade` / `ResponseState` | Garantie single-response, statusCode tracking |
| `BlockingPool` | Process isolation pour code bloquant/CPU-bound, IPC framé |
| `JobRegistry` | Map jobName → callable pour le BlockingPool |
| `ExecutionPolicy` / `IoExecutor` | Politique coroutine-safe centralisée par dépendance |
| `HealthController` | /healthz (liveness) + /readyz (readiness + lag) |
| `WorkerLifecycle` | Tick timer 250ms, lag monitor, reload check |
| `ReloadPolicy` | max_requests / max_uptime / max_memory_rss |
| `GracefulShutdown` | SIGTERM/SIGINT, drain scopes, timeout hard |
| `JsonLogger` | PSR-3, NDJSON sur stderr |
| `RequestIdMiddleware` | Extract/generate UUIDv4, propagation header |
| `MetricsCollector` | Compteurs, histogramme, gauges, lag, scope_rejected |
| `IpcFraming` | Protocole uint32 length prefix + JSON pour IPC BlockingPool |

### Contrat Async (invariants)

- Pas de spawn global : toute coroutine a un scope parent (TaskScope)
- Pas de coroutine sans deadline : héritée du RequestContext
- Cancellation propagée fail-fast par défaut
- BlockingPool borné : queue finie + timeout (pas de croissance illimitée)
- Tout CPU-bound > 10ms doit être offload vers BlockingPool
- Concurrence bornée par worker via sémaphore (si maxConcurrentScopes > 0)
- Politique d'exécution centralisée : chaque dépendance IO a une stratégie
- Event-loop lag monitoré activement (drift-based)

## Configuration

Toute la configuration se fait par variables d'environnement, validées au démarrage. Voir [docs/configuration.md](docs/configuration.md) pour la référence complète.

### Variables principales

| Variable | Défaut | Description |
| --- | --- | --- |
| `APP_HOST` | `0.0.0.0` | Adresse de bind |
| `APP_PORT` | `8080` | Port de bind |
| `APP_WORKERS` | `0` (auto) | Nombre de workers. `0` = auto |
| `MAX_REQUESTS` | `10000` | Reload worker après N requêtes (`0` = désactivé) |
| `MAX_UPTIME` | `3600` | Reload worker après N secondes (`0` = désactivé) |
| `MAX_MEMORY_RSS` | `134217728` | Reload worker à 128 MB RSS (`0` = désactivé) |
| `SHUTDOWN_TIMEOUT` | `30` | Timeout hard du graceful shutdown (secondes) |
| `REQUEST_HANDLER_TIMEOUT` | `60` | Deadline par requête (secondes) |
| `MAX_CONCURRENT_SCOPES` | `0` | Max scopes concurrents par worker (`0` = illimité) |
| `EVENT_LOOP_LAG_THRESHOLD_MS` | `500` | Seuil lag event loop pour /readyz (`0` = désactivé) |
| `BLOCKING_POOL_WORKERS` | `4` | Workers du BlockingPool |
| `BLOCKING_POOL_QUEUE_SIZE` | `64` | Capacité queue outbound |
| `BLOCKING_POOL_TIMEOUT` | `30` | Timeout par job (secondes) |

## Matrice de compatibilité

| Composant | Version cible |
| --- | --- |
| PHP | 8.4+ |
| OpenSwoole | 22.x (dernière stable via pecl) |
| OS container | Linux (Debian bookworm-slim) |
| Architecture | amd64, arm64 |
| RSS monitoring | Linux only (`/proc/self/statm`) |

## Healthchecks

| Endpoint | Comportement |
| --- | --- |
| `GET /healthz` | `200 {"status":"alive"}` tant que le process est actif (même en shutdown) |
| `GET /readyz` | `200 {"status":"ready","event_loop_lag_ms":...}` si prêt |
| `GET /readyz` | `503 {"status":"shutting_down"}` pendant le shutdown |
| `GET /readyz` | `503 {"status":"event_loop_stale"}` si tick > 2s |
| `GET /readyz` | `503 {"status":"event_loop_lagging","lag_ms":...}` si lag > seuil |

Les deux endpoints incluent `Cache-Control: no-store` et `Content-Type: application/json`.

## Graceful Shutdown

1. SIGTERM → `shuttingDown = true` sur tous les workers
2. Nouvelles requêtes (hors /healthz, /readyz) → 503
3. `/readyz` → 503 (le LB retire le pod)
4. Scopes actifs annulés, drain avec grace period 1s
5. Après drain ou `SHUTDOWN_TIMEOUT` → `$server->shutdown()`
6. BlockingPool stop après les workers

Double SIGTERM → arrêt forcé immédiat. SIGINT en dev → arrêt immédiat propre.

## Proxy frontal (obligatoire en prod)

Le runtime pack est un serveur applicatif HTTP, pas un serveur web. En production, placer un reverse proxy devant (Caddy ou Nginx) pour :

- TLS / HSTS / certificats
- Compression (gzip, brotli)
- Fichiers statiques
- Headers de sécurité
- Protection anti-slowloris (le runtime pack ne garantit pas le read-timeout en V1)

Voir [skeleton/README.md](skeleton/README.md) pour les configurations Nginx et Caddy minimales.

## Structure du repo

```text
packages/                        # Packages Composer publiables sur Packagist
  runtime-pack/                  #   octo-php/runtime-pack — cœur OpenSwoole
  symfony-bridge/                #   octo-php/symfony-bridge — adaptateur HttpKernel
  symfony-bundle/                #   octo-php/symfony-bundle — auto-config Symfony
  symfony-messenger/             #   octo-php/symfony-messenger — transport in-process
  symfony-otel/                  #   octo-php/symfony-otel — traces & métriques OTEL
  symfony-realtime/              #   octo-php/symfony-realtime — WebSocket & SSE
  symfony-bridge-full/           #   octo-php/symfony-bridge-full — meta-package suite
platform/                        # Runtime & intégrations internes (non publié)
skeleton/                        # Template create-project (composer create-project)
docs/                            # Documentation suite (ADR, configuration, design)
```

Le root `composer.json` pilote tous les packages via `"repositories": [{"type":"path","url":"packages/*"}]`. Un seul `composer install` à la racine suffit pour développer.

## Tests

```bash
# Depuis la racine du monorepo (lance tous les packages)
composer test

# Par suite
composer test:unit
composer test:property
composer test:integration

# Un package spécifique
cd packages/runtime-pack && vendor/bin/phpunit
cd packages/symfony-bridge && vendor/bin/phpunit
```

Chaque package conserve son propre `phpunit.xml.dist` pour le dev isolé. Le `phpunit.xml.dist` root agrège toutes les suites.

### Couverture

- Tests unitaires : tous les composants (ServerConfig, JsonLogger, RequestIdMiddleware, HealthController, ReloadPolicy, WorkerLifecycle, MetricsCollector, GracefulShutdown, RequestHandler, ResponseState, ResponseFacade, BlockingPool, ExecutionPolicy, IoExecutor, ScopeRunner, TaskScope, IpcFraming, JobRegistry)
- Tests property-based : config validation round-trip, readyz déterministe, request ID résolution, sémaphore concurrence, ExecutionPolicy résolution
- Tests d'intégration : serveur réel (healthz, readyz, shutdown, SIGTERM/SIGINT, event-loop lag, sémaphore, BlockingPool, IPC framing, ResponseFacade, version headers)

## Documentation

| Document | Contenu |
| --- | --- |
| [docs/configuration.md](docs/configuration.md) | Variables d'environnement, validation, mapping OpenSwoole, ExecutionPolicy, event-loop lag monitor |
| [docs/adr/001](docs/adr/001-runtime-pack-decisions.md) | Décisions architecturales : Debian vs Alpine, UUIDv4, exit(0) reload, sémaphore Channel, ExecutionPolicy, IPC framing, ResponseFacade, NDJSON |
| [skeleton/README.md](skeleton/README.md) | Guide complet du skeleton : handlers async-safe, proxy frontal, Docker, IoExecutor |

## Release Process

Versioning global : un tag `vX.Y.Z` au niveau du monorepo est propagé automatiquement vers tous les repos split via le [workflow split](.github/workflows/split.yml).

```bash
# Bump patch (0.1.0 → 0.1.1), minor, ou major
./scripts/release.sh patch "optional title"
```

Le script vérifie la branche (`main`) et le working tree, met à jour le [CHANGELOG.md](CHANGELOG.md), crée un tag annoté, et pousse. Si `gh` est installé, une GitHub Release est créée automatiquement.

Pas de tags par package : le split workflow se charge de la propagation.

## Roadmap

Le Runtime Pack V1 est le socle. Les prochaines specs :

- Concurrency Core V1 : Context + TaskGroup + timeout/limit imposés (primitives publiques)
- Observability V1 : OTEL traces + métriques exportées + endpoint /metrics
- Tooling CLI V1 : async:doctor / async:bench
- HTTP layer : routing/middleware avancé + exemples fan-out + streaming

## Contrib

- Toute PR structurelle → ADR
- Toute nouvelle primitive publique → tests + docs + exemple
