# octo-php/runtime-pack

OpenSwoole runtime pack — serveur HTTP prod-safe avec healthchecks, graceful shutdown, reload policy, structured concurrency, et observabilité.

## Prérequis

- PHP >= 8.3
- Extension `ext-openswoole`
- Licence : MIT

## Installation

```bash
composer require octo-php/runtime-pack
```

## Usage minimal

```php
<?php
declare(strict_types=1);

use Octo\RuntimePack\ServerBootstrap;

require_once __DIR__ . '/vendor/autoload.php';

ServerBootstrap::run(
    appHandler: function (object $request, object $response): void {
        $response->status(200);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['message' => 'Hello from async PHP']));
    },
    production: false,
);
```

`ServerBootstrap::run()` démarre le serveur OpenSwoole, configure les workers, active les hooks coroutine, et orchestre tous les composants décrits ci-dessous.

## Composants

### ServerBootstrap

Point d'entrée principal. `ServerBootstrap::run(callable $handler, bool $production)` démarre le serveur, configure les workers, les hooks coroutine, et orchestre l'ensemble des composants.

### ServerConfig / ServerConfigFactory

Configuration immutable chargée depuis les variables d'environnement. Validation stricte au boot — fail-fast si une valeur est invalide.

### RequestHandler

Dispatch des requêtes HTTP :

- Routing interne (`/healthz`, `/readyz`)
- Délégation au handler applicatif
- Access log NDJSON
- Métriques par requête

### ScopeRunner

Exécution du handler dans un scope managé :

- Deadline par requête via `Timer::after` → réponse 408 si timeout
- Protection double-send via `ResponseState`
- Sémaphore de concurrence → réponse 503 si capacité atteinte

### ResponseFacade / ResponseState

Wrapper autour de `OpenSwoole\Http\Response` avec protection double-send :

- `end()` ne peut être appelé qu'une seule fois
- `write()` pour le streaming partiel

### GracefulShutdown

Gestion des signaux d'arrêt :

- Premier `SIGTERM` → drain graceful avec hard timer
- Double `SIGTERM` → arrêt forcé immédiat
- `SIGINT` en dev → arrêt immédiat

### ReloadPolicy / WorkerLifecycle

Politique de reload worker configurable :

- Max requests par worker
- Max memory RSS
- Max uptime
- Anti crash-loop guard (intervalle min entre restarts)
- Monitoring event loop lag (tick 250ms)

### HealthController

Deux endpoints intégrés :

- `GET /healthz` → `{"status":"alive"}` (200 tant que le process est actif)
- `GET /readyz` → `{"status":"ready","event_loop_lag_ms":0.5}` (200) ou 503 avec raison :
  - `{"status":"shutting_down"}` — shutdown en cours
  - `{"status":"event_loop_stale"}` — event loop bloquée
  - `{"status":"event_loop_lagging"}` — lag au-dessus du seuil

### MetricsCollector

Compteurs, gauges, histogramme bucketisé pour les durées de requête. Snapshot programmatique via `MetricsCollector::snapshot()`.

### JsonLogger

Logger PSR-3 NDJSON avec contexte immutable :

- `withComponent(string $name)` — préfixe composant
- `withRequestId(string $id)` — corrélation par requête

Compatible ELK / Loki / CloudWatch.

### BlockingPool

Pool de processus isolés pour I/O bloquantes (Doctrine legacy, FFI, CPU-bound) :

- Queue bornée avec backpressure
- Timeout par job
- Métriques intégrées
- IPC via UnixSocket avec framing uint32

### IoExecutor / ExecutionPolicy

Routage automatique des I/O :

- Coroutine directe si le driver est hooké (redis, file_io, guzzle+curl)
- Offload vers `BlockingPool` sinon

Matrice de compatibilité async centralisée dans `ExecutionPolicy`.

### RequestIdMiddleware

Extraction ou génération du header `X-Request-Id` pour chaque requête entrante.

## BlockingPool — usage

```php
use Octo\RuntimePack\IoExecutor;

// Via IoExecutor (recommandé — routage automatique)
$result = $io->run(
    dependency: 'pdo_mysql',
    jobName: 'user.find',
    payload: ['id' => 42],
);

// Accès direct au BlockingPool
$result = $blockingPool->run('user.find', ['id' => 42], timeout: 5.0);
```

`IoExecutor` consulte la matrice `ExecutionPolicy` pour décider si l'appel peut rester en coroutine ou doit être offloadé vers le pool de processus isolés.

## Variables d'environnement

| Variable | Type | Défaut | Description |
| -------- | ---- | ------ | ----------- |
| `OCTOP_HOST` | string | `0.0.0.0` | Adresse de bind |
| `OCTOP_PORT` | int | `8080` | Port de bind |
| `OCTOP_WORKERS` | int | `0` (auto) | Nombre de workers (0 = auto-detect CPU cores) |
| `OCTOP_MAX_REQUEST_BODY_SIZE` | int (bytes) | `2097152` (2 MB) | Taille max du body HTTP |
| `OCTOP_MAX_CONNECTIONS` | int | `1024` | Connexions simultanées max |
| `OCTOP_REQUEST_HANDLER_TIMEOUT` | int (s) | `60` | Deadline applicative par requête |
| `OCTOP_SHUTDOWN_TIMEOUT` | int (s) | `30` | Timeout graceful shutdown |
| `OCTOP_MAX_REQUESTS` | int | `10000` | Max requêtes par worker avant reload (0 = désactivé) |
| `OCTOP_MAX_UPTIME` | int (s) | `3600` | Max uptime worker avant reload (0 = désactivé) |
| `OCTOP_MAX_MEMORY_RSS` | int (bytes) | `134217728` (128 MB) | Max RSS worker avant reload (0 = désactivé) |
| `OCTOP_WORKER_RESTART_MIN_INTERVAL` | int (s) | `5` | Intervalle min entre restarts (anti crash-loop) |
| `OCTOP_BLOCKING_POOL_WORKERS` | int | `4` | Nombre de workers du BlockingPool (0 = désactivé) |
| `OCTOP_BLOCKING_POOL_QUEUE_SIZE` | int | `64` | Capacité de la queue bornée du BlockingPool |
| `OCTOP_BLOCKING_POOL_TIMEOUT` | int (s) | `30` | Timeout par défaut des jobs BlockingPool |
| `OCTOP_MAX_CONCURRENT_SCOPES` | int | `0` | Limite de scopes concurrents par worker (0 = illimité) |
| `OCTOP_EVENT_LOOP_LAG_THRESHOLD_MS` | float (ms) | `500.0` | Seuil de lag event loop pour /readyz 503 (0 = désactivé) |

## Métriques

Exposées via `MetricsCollector::snapshot()` :

| Métrique | Type | Description |
| -------- | ---- | ----------- |
| `requests_total` | counter | Requêtes traitées |
| `request_duration_ms` | histogram | Durée de traitement (buckets : 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000 ms) |
| `workers_configured` | gauge | Nombre de workers configurés |
| `memory_rss_bytes` | gauge | RSS mémoire du worker |
| `inflight_scopes` | gauge | Scopes actifs |
| `cancelled_requests_total` | counter | Requêtes annulées (deadline dépassée) |
| `blocking_tasks_total` | counter | Jobs BlockingPool exécutés |
| `blocking_queue_depth` | gauge | Profondeur de la queue BlockingPool |
| `blocking_pool_rejected` | counter | Jobs rejetés (queue pleine) |
| `blocking_pool_busy_workers` | gauge | Workers BlockingPool occupés |
| `event_loop_lag_ms` | gauge | Lag de l'event loop |
| `scope_rejected_total` | counter | Scopes rejetés (sémaphore pleine) |

## Healthchecks

Deux endpoints intégrés, routés en interne avant le handler applicatif :

```text
GET /healthz → 200 {"status":"alive"}
GET /readyz  → 200 {"status":"ready","event_loop_lag_ms":0.5}
             → 503 {"status":"shutting_down"}
             → 503 {"status":"event_loop_stale"}
             → 503 {"status":"event_loop_lagging","event_loop_lag_ms":850.2}
```

`/healthz` répond 200 tant que le process est vivant. `/readyz` vérifie l'état réel du worker : shutdown en cours, event loop bloquée ou lag excessif.

## Graceful shutdown

Séquence d'arrêt :

1. `SIGTERM` → le worker cesse d'accepter de nouvelles requêtes (réponse 503)
2. Les requêtes en cours se terminent naturellement (drain)
3. Hard timer (`OCTOP_SHUTDOWN_TIMEOUT`) → arrêt forcé si le drain n'est pas terminé
4. Double `SIGTERM` → arrêt forcé immédiat

En mode dev, `SIGINT` provoque un arrêt immédiat sans drain.

## Vérifications au boot (production)

En mode `production: true`, le serveur vérifie au démarrage :

- **Coroutine hooks** — `SWOOLE_HOOK_ALL` doit être activé. Fail-fast sinon.
- **Xdebug** — détection et fail-fast (incompatible avec le scheduling coroutine).
- **SWOOLE_HOOK_CURL** — statut loggé (impact sur les clients HTTP).

## Commandes

```bash
bin/console async:serve   # Démarrage dev (auto-reload, logs verbeux)
bin/console async:run     # Démarrage production
bin/console async:doctor  # Diagnostic runtime (extensions, hooks, config)
bin/console async:bench   # Benchmark intégré
```

## Packages complémentaires (Symfony Bridge Suite)

| Package | Description |
| ------- | ----------- |
| [symfony-bridge](../symfony-bridge/) | Core Symfony HttpKernel adapter |
| [symfony-bundle](../symfony-bundle/) | Auto-configuration Symfony, recipe Flex |
| [symfony-messenger](../symfony-messenger/) | Transport Messenger in-process |
| [symfony-realtime](../symfony-realtime/) | WebSocket + helpers SSE avancés |
| [symfony-otel](../symfony-otel/) | Export OpenTelemetry |
| [symfony-bridge-full](../symfony-bridge-full/) | Meta-package installant toute la suite |

## Licence

MIT
