# Tasks — Runtime Pack OpenSwoole + Skeleton

## Task 1 : Skeleton de code (structure du projet)

- [x] 1.1 Créer la structure de répertoires `packages/runtime-pack/src/`, `packages/runtime-pack/tests/`, `platform/`, `skeleton/`, `docs/`
- [x] 1.2 Créer `packages/runtime-pack/composer.json` avec namespace `AsyncPlatform\RuntimePack`, dépendances `psr/log`, `ext-openswoole`, require-dev `phpunit/phpunit`, `giorgiosironi/eris`
- [x] 1.3 Créer `skeleton/composer.json` avec dépendance `async-platform/runtime-pack`, type `project`
- [x] 1.4 Créer `bin/console` minimal (point d'entrée CLI avec parsing `async:serve` / `async:run`)
- [x] 1.5 Créer les fichiers d'autoload PSR-4 et vérifier que `composer dump-autoload` fonctionne
  **Implémente : Requirements 1.1, 1.2, 6.1**

## Task 2 : ServerConfigFactory + validation + ConfigValidationException

- [x] 2.1 Créer `ServerConfig` (DTO readonly) avec tous les champs du design (host, port, workers, production, maxRequestBodySize, maxConnections, requestHandlerTimeout, shutdownTimeout, maxRequests, maxUptime, maxMemoryRss, workerRestartMinInterval, blockingPoolWorkers, blockingPoolQueueSize, blockingPoolTimeout, maxConcurrentScopes, eventLoopLagThresholdMs)
- [x] 2.2 Créer `ConfigValidationException` avec le tableau `errors` (variable → message)
- [x] 2.3 Créer `ServerConfigFactory::fromEnvironment()` : lecture des env vars, validation des types et bornes (port 1-65535, entiers >= 0, eventLoopLagThresholdMs >= 0, etc.), lancement de `ConfigValidationException` si invalide
- [x] 2.4 Implémenter `resolveWorkers()` : prod → `swoole_cpu_num()`, dev → 2, configuré → valeur
- [x] 2.5 Implémenter la vérification prod : warning si toutes les reload policies sont désactivées (maxRequests=0 ET maxUptime=0 ET maxMemoryRss=0)
- [x] 2.6 Créer `docs/configuration.md` avec la table de toutes les variables d'environnement, types, défauts, descriptions (incluant EVENT_LOOP_LAG_THRESHOLD_MS et MAX_CONCURRENT_SCOPES)
  **Implémente : Requirements 1.3, 1.4, 1.5, 8.1, 8.2, 8.3, 8.4 | Property 1**

## Task 3 : JsonLogger NDJSON (PSR-3) + access log

- [x] 3.1 Créer `JsonLogger` implémentant `Psr\Log\LoggerInterface`, écriture NDJSON sur stderr
- [x] 3.2 Implémenter le format : `{"timestamp":"RFC3339 UTC","level":"...","message":"...","component":"...","request_id":null,"extra":{}}` — une seule ligne JSON par log
- [x] 3.3 Implémenter les méthodes PSR-3 (emergency, alert, critical, error, warning, notice, info, debug) déléguées à `log()`
- [x] 3.4 Gérer les caractères spéciaux dans message (JSON encoding, pas d'échappement cassé)
- [x] 3.5 Supporter le champ `component` via le context array (`$context['component']`)
  **Implémente : Requirements 7.1, 7.2, 7.3, 1.6 | Property 2, Property 3**

## Task 4 : RequestIdMiddleware + header propagation + validation

- [x] 4.1 Créer `RequestIdMiddleware` avec méthode `resolve(Request): string`
- [x] 4.2 Implémenter l'extraction du header `X-Request-Id` entrant
- [x] 4.3 Implémenter la validation : longueur <= 128 chars, ASCII only (codes 32-126). Si invalide → générer nouveau + log warning (sans refléter l'input brut)
- [x] 4.4 Implémenter `generateUuidV4()` via `random_bytes(16)` avec bits version 4 et variant 1
- [x] 4.5 Implémenter la propagation : header `X-Request-Id` dans la réponse HTTP
  **Implémente : Requirements 10.1, 10.2, 10.3, 10.5 | Property 9, Property 10, Property 11**

## Task 5 : HealthController + WorkerLifecycle (tick timer, event loop lag monitor, inflight count)

- [x] 5.1 Créer `WorkerLifecycle` avec tick timer 250ms mettant à jour `lastLoopTickAt` et mesurant activement le lag de l'event loop (drift entre tick attendu et tick réel)
- [x] 5.2 Implémenter `isEventLoopHealthy()` : retourne false si tick stale (> 2s) OU si lag > seuil configurable (eventLoopLagThresholdMs)
- [x] 5.3 Implémenter `beginRequest()` / `endRequest()` pour le compteur `inflightCount` (incrémenté au début de handle, décrémenté dans finally)
- [x] 5.4 Implémenter `isShuttingDown()`, `startShutdown()`, `getInflightCount()`, `getWorkerId()`, `getEventLoopLagMs()`
- [x] 5.5 Créer `HealthController` avec `healthz()` → 200 `{"status":"alive"}` + `Content-Type: application/json` + `Cache-Control: no-store`
- [x] 5.6 Implémenter `readyz()` → 200 `{"status":"ready","event_loop_lag_ms":...}` si ready, 503 `{"status":"shutting_down"}` si shutdown, 503 `{"status":"event_loop_stale"}` si tick stale, 503 `{"status":"event_loop_lagging","lag_ms":...}` si lag > seuil. Toujours `Content-Type: application/json` + `Cache-Control: no-store`
- [x] 5.7 Implémenter la métrique `event_loop_lag_ms` dans MetricsCollector, mise à jour par `tick()`
- [x] 5.8 Implémenter l'heuristique CPU-bound dans `tick()` : si eventLoopLagMs > 2× seuil et inflightScopes > 0, log warning "probable CPU-bound in handler" avec worker_id et inflight_scopes
  **Implémente : Requirements 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8 | Property 4, Property 5, Property 18**

## Task 6 : GracefulShutdown (SIGTERM/SIGINT, drain, timeout hard, refus 503)

- [x] 6.1 Créer `GracefulShutdown` avec `register(Server)` : handler SIGTERM dans chaque worker (onWorkerStart) + handler master-side (onStart) comme filet de sécurité ($server->shutdown() après timeout hard)
- [x] 6.2 Implémenter SIGTERM : set `shuttingDown = true` sur tous les workers, log shutdown started (worker_inflight, timeout)
- [x] 6.3 Implémenter le refus applicatif : `onRequest` vérifie `shuttingDown`, routes non-health → 503 `{"error":"Server shutting down"}`
- [x] 6.4 Implémenter `/readyz` → 503 pendant shutdown, `/healthz` → 200 tant que process actif
- [x] 6.5 Implémenter le timeout hard via `Timer::after(shutdownTimeout, fn() => $server->shutdown())`
- [x] 6.6 Implémenter le log de fin de shutdown : clean (toutes requêtes terminées) ou forced (timeout atteint)
- [x] 6.7 Implémenter double SIGTERM → arrêt forcé immédiat
- [x] 6.8 Implémenter SIGINT en dev → arrêt immédiat propre
  **Implémente : Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7**

## Task 7 : ReloadPolicy (max_requests natif + max_uptime exit(0) + max_memory_rss + WORKER_RESTART_MIN_INTERVAL)

- [x] 7.1 Créer `ReloadPolicy` avec `shouldReload(requestCount, uptimeSeconds, memoryRssBytes): ?ReloadReason`
- [x] 7.2 Créer `ReloadReason` enum (MaxRequests, MaxUptime, MaxMemoryRss)
- [x] 7.3 Implémenter la logique : seuil désactivé (=0) → ignoré, ordre de priorité max_requests > max_memory_rss > max_uptime
- [x] 7.4 Implémenter `readMemoryRss()` via `/proc/self/statm` (champ 1 × page_size). Retourne null si non disponible.
- [x] 7.5 Implémenter le throttling RSS dans `WorkerLifecycle::afterRequest()` : toutes les 100 requêtes OU toutes les 5 secondes
- [x] 7.6 Implémenter le garde-fou `WORKER_RESTART_MIN_INTERVAL` : si worker démarré il y a < N secondes, ignorer le reload + log warning
- [x] 7.7 Implémenter le log de reload avec `worker_id`, `reload_reason`, et valeurs courantes (request_count, uptime_seconds, memory_rss_bytes)
- [x] 7.8 Implémenter la priorité shutdown > reload : si `isShuttingDown()`, ne pas déclencher de reload
- [x] 7.9 Implémenter le flag `shouldExitAfterCurrentRequest` posé uniquement après `response->end()`
  **Implémente : Requirements 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8 | Property 6**

## Task 8 : MetricsCollector (compteurs internes, histogram bucketisé, métriques V1 hardening)

- [x] 8.1 Créer `MetricsCollector` avec compteur `requestsTotal`, gauges `workersActive` et `memoryRssBytes`
- [x] 8.2 Implémenter l'histogramme bucketisé pour `request_duration_ms` : buckets [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000] ms + Inf, avec sum, count, min, max
- [x] 8.3 Implémenter `incrementRequests()`, `recordDuration(float $durationMs)`, `setWorkersActive(int)`, `setMemoryRss(int)`
- [x] 8.4 Implémenter `snapshot(): array` retournant un tableau structuré de toutes les métriques (incluant event_loop_lag_ms, scope_rejected_total, blocking_pool_busy_workers, blocking_pool_rejected)
- [x] 8.5 Implémenter `setEventLoopLagMs(float)`, `incrementScopeRejected()`, `setBlockingPoolBusyWorkers(int)` pour les métriques V1 hardening
  **Implémente : Requirements 7.4 | Property 12**

## Task 9 : ServerBootstrap (assemblage, onWorkerStart, onRequest, settings OpenSwoole)

- [x] 9.1 Créer `ServerBootstrap::run(callable $appHandler, bool $production)` — point d'entrée principal
- [x] 9.2 Implémenter le chargement et la validation de la config via `ServerConfigFactory`
- [x] 9.3 Implémenter le mapping `ServerConfig` → settings OpenSwoole (`worker_num`, `package_max_length`, `max_connection`, `max_request`, `open_http_protocol`, `http_compression`)
- [x] 9.4 Implémenter `onWorkerStart` : instanciation de `WorkerLifecycle`, `MetricsCollector`, `JsonLogger`, `ReloadPolicy`, `HealthController`, `RequestIdMiddleware`, `RequestHandler` ; démarrage du tick timer 250ms ; appel `BlockingPool::initWorker()` (channel outbound + dispatcher) ; création de la reader coroutine IPC ; création de `ExecutionPolicy::defaults($hookFlags)`
- [x] 9.5 Implémenter `onRequest` : délégation au `RequestHandler`
- [x] 9.6 Enregistrer le `GracefulShutdown` sur le serveur
- [x] 9.7 Implémenter le log de démarrage JSON (host, port, workers, mode, component='runtime')
- [x] 9.8 Vider les headers de version via les settings OpenSwoole (`http_server_software => ''`) et/ou `$response->header('Server', '')` et `$response->header('X-Powered-By', '')` dans RequestHandler::handle(). Vérifier le mécanisme exact dans la doc OpenSwoole et valider par un test d'intégration que les headers sont effectivement absents de la réponse.
  **Implémente : Requirements 1.1, 1.2, 1.6, 9.4, 9.5 | Property 7, Property 8**

## Task 10 : RequestHandler (routing interne, ScopeRunner avec sémaphore, access log, metrics)

- [x] 10.1 Créer `RequestHandler::handle(Request, Response)` — point d'entrée par requête
- [x] 10.2 Implémenter le routing interne : `/healthz` → `HealthController::healthz()`, `/readyz` → `HealthController::readyz()`, autres → app handler
- [x] 10.3 Implémenter le refus 503 si `shuttingDown` et request_uri ∉ {/healthz, /readyz} — APRÈS resolve request-id et set X-Request-Id (Property 11), AVANT beginRequest() (pas de comptage métrique). Log access même sur 503 shutdown. Priorité : shutdown (503) gagne sur timeout handler (408).
- [x] 10.4 Implémenter le ScopeRunner avec sémaphore de concurrence (maxConcurrentScopes) : si sémaphore plein → 503 immédiat + Content-Type:application/json + Retry-After:1 + métrique scope_rejected. Sinon → exécution normale avec deadline via Timer::after(). Timer callback : setStatusCode(408) AVANT trySend(), Content-Type:application/json. Exception handler : setStatusCode(500) AVANT trySend(), Content-Type:application/json.
- [x] 10.5 Implémenter l'access log NDJSON après chaque requête : method, path, status_code (depuis ResponseState.getStatusCode()), duration_ms, request_id
- [x] 10.6 Implémenter l'incrémentation des métriques (requests_total, duration histogram)
- [x] 10.7 Implémenter le check `shouldExit()` après l'access log — si true, `exit(0)` pour reload gracieux
- [x] 10.8 Implémenter la gestion des exceptions non catchées dans le handler utilisateur : log error (sans détails en prod), réponse 500
  **Implémente : Requirements 2.1, 2.2, 7.3, 9.3, 9.5 | Property 3, Property 8, Property 17, Property 19**

## Task 11 : Dockerfile multi-stage (dev + prod) + docker-compose.yml

- [x] 11.1 Créer le Dockerfile multi-stage : stage `base` (PHP 8.3 cli bookworm + OpenSwoole via pecl), stage `dev` (+ Composer + Xdebug), stage `prod` (+ OPcache, user non-root, HEALTHCHECK)
- [x] 11.2 Créer `docker/php/opcache.ini` avec configuration OPcache prod
- [x] 11.3 Créer `docker-compose.yml` avec service app (stage dev), port 8080, volumes pour le code source
- [x] 11.4 Vérifier que l'image prod expose le port 8080, exécute en user non-root, et a le HEALTHCHECK Docker sur /healthz
- [x] 11.5 Supporter les architectures amd64 et arm64 (pas de dépendance binaire spécifique)
  **Implémente : Requirements 5.1, 5.2, 5.3, 5.4, 5.5, 5.6**

## Task 12 : Skeleton create-project (structure, HomeHandler, .env.example, README.md)

- [x] 12.1 Créer `skeleton/src/Handler/HomeHandler.php` : GET / → 200 `{"message":"Hello, Async PHP!"}`
- [x] 12.2 Créer `skeleton/config/routes.php` avec la définition des routes utilisateur
- [x] 12.3 Créer `skeleton/bin/console` avec parsing `async:serve` / `async:run` et appel à `ServerBootstrap::run()`
- [x] 12.4 Créer `skeleton/.env.example` listant toutes les variables d'environnement avec défauts (incluant EVENT_LOOP_LAG_THRESHOLD_MS, MAX_CONCURRENT_SCOPES)
- [x] 12.5 Créer `skeleton/README.md` documentant : commandes (async:serve, async:run), configuration par env vars, architecture, endpoints opérationnels (/healthz, /readyz avec lag_ms), mention proxy frontal (Caddy/Nginx) pour HSTS/compression/static, règles async-safe (incluant Règle 7 IoExecutor)
- [x] 12.6 Créer `skeleton/Dockerfile` et `skeleton/docker-compose.yml` adaptés au skeleton
- [x] 12.7 Créer `skeleton/config/execution_policy.php` avec la configuration ExecutionPolicy par défaut et des commentaires explicatifs
  **Implémente : Requirements 6.1, 6.2, 6.3, 6.4, 6.5**

## Task 13 : Tests unitaires (tous les composants, edge cases, V1 hardening)

- [x] 13.1 Tests `ServerConfigFactory` : valeurs par défaut dev/prod, APP_WORKERS=0 résolution, port invalide (0, 99999, non-numérique), workers négatif, toutes les reload policies désactivées en prod → warning, EVENT_LOOP_LAG_THRESHOLD_MS validation, MAX_CONCURRENT_SCOPES validation
- [x] 13.2 Tests `ConfigValidationException` : tableau errors contient les bonnes variables, message formaté
- [x] 13.3 Tests `JsonLogger` : format NDJSON valide, caractères spéciaux dans message, request_id null, tous les levels PSR-3, champ component présent
- [x] 13.4 Tests `RequestIdMiddleware` : pas de header → génère UUIDv4, header valide → réutilisé, header > 128 chars → rejeté + nouveau, header non-ASCII → rejeté + nouveau, header vide → génère
- [x] 13.5 Tests `HealthController` : /healthz → 200 + Content-Type:application/json + Cache-Control, /readyz → 200 avec lag_ms + Content-Type:application/json si ready, /readyz → 503 si shutdown, /readyz → 503 si event loop stale, /readyz → 503 si lag > seuil avec lag_ms dans le body
- [x] 13.6 Tests `ReloadPolicy` : seuil atteint → raison correcte, seuil désactivé (=0) → null, /proc/self/statm non dispo → null + warning, priorité max_requests > max_memory_rss > max_uptime, memoryRssBytes = null (throttlé) → seuil mémoire ignoré
- [x] 13.7 Tests `WorkerLifecycle` : tick met à jour lastLoopTickAt et calcule le lag, lag = 0 si tick à l'heure, lag > 0 si tick en retard, isEventLoopHealthy() combine tick stale + lag seuil, heuristique CPU-bound : lag > 2× seuil + inflightScopes > 0 → warning log, afterRequest incrémente compteur, inflightCount begin/end, shouldExit après reload, shutdown prioritaire sur reload, WORKER_RESTART_MIN_INTERVAL respecté
- [x] 13.8 Tests `MetricsCollector` : snapshot initial à zéro, incrementRequests, recordDuration dans le bon bucket, setWorkersActive/setMemoryRss, sum/count/min/max cohérents, setEventLoopLagMs, incrementScopeRejected, setBlockingPoolBusyWorkers
- [x] 13.9 Tests `GracefulShutdown` : vérifier que les handlers de signaux sont enregistrés (mock server)
- [x] 13.10 Tests `RequestHandler` : routing /healthz, /readyz, app route, refus 503 en shutdown, exception handler → 500
- [x] 13.11 Tests `ResponseState` : trySend() true once, subsequent false, statusCode tracking, hasExplicitStatusCode() false par défaut, true après setStatusCode()
- [x] 13.12 Tests `ResponseFacade` : end() sans status() → setStatusCode(200) via hasExplicitStatusCode(), end() avec status(201) → statusCode=201 préservé, end() twice → false + warning, status/header after end → ignoré
- [x] 13.13 Tests `BlockingPool` : Queue full (outbound channel) → exception, timeout → exception, unknown job → exception, sendToPool failure → BlockingPoolSendException propagée via dispatcher, job_id correlation, runOrRespondError (Full→503+Retry-After, Timeout→504, SendFailed→502, RuntimeException→500, succès→résultat), cleanupOrphanedJobs supprime les channels fermés, queueDepth = outbound channel length, inflightCount = count(pendingJobs)
- [x] 13.14 Tests `ExecutionPolicy` : defaults($hookFlags) contient les bonnes stratégies par dépendance, guzzle=DirectCoroutineOk si SWOOLE_HOOK_CURL actif sinon ProbeRequired, resolve() pour dépendance inconnue → MustOffload, canRunDirect() true seulement pour DirectCoroutineOk, register() + resolve() round-trip
- [x] 13.15 Tests `IoExecutor` : DirectCoroutineOk + directCallable → appel direct, MustOffload → offload BlockingPool, ProbeRequired → offload + log debug, pas de directCallable → toujours offload
- [x] 13.16 Tests `ScopeRunner` sémaphore : maxConcurrentScopes=0 → pas de sémaphore, maxConcurrentScopes=2 → sémaphore créé avec 2 tokens, sémaphore plein → 503 immédiat + Content-Type:application/json + Retry-After + métrique, sémaphore relâché dans finally, log warning utilise maxConcurrentScopes (pas channel.capacity)
- [x] 13.17 Tests `TaskScope::joinAll()` : batch join via Coroutine::join($cids), grace period avec timeout restant calculé dynamiquement, CIDs inactifs filtrés avant join
  **Implémente : Couverture unitaire de tous les composants, edge cases des Requirements 1-10, V1 hardening**

## Task 14 : Tests property-based V1 (Property 1, Property 4, Property 9, Property 19, Property 20)

- [x] 14.1 Configurer Eris (ou PhpQuickCheck) dans le projet, minimum 100 itérations par test
- [x] 14.2 PBT Property 1 — Config validation round-trip : générer des combinaisons valides d'env vars (incluant EVENT_LOOP_LAG_THRESHOLD_MS, MAX_CONCURRENT_SCOPES) → ServerConfig correspond ; générer des combinaisons avec au moins une valeur invalide → ConfigValidationException avec les bonnes variables. Tag : `Feature: runtime-pack-openswoole, Property 1: Config validation round-trip`
- [x] 14.3 PBT Property 4 — Logique readyz déterministe avec lag : générer des tuples (shuttingDown, lastLoopTickAt, currentTime, eventLoopLagMs, lagThresholdMs) → vérifier que la décision readyz est déterministe et correcte (shutdown→503, stale→503, lag>seuil→503, sinon→200). Tag : `Feature: runtime-pack-openswoole, Property 4: Logique readyz déterministe`
- [x] 14.4 PBT Property 9 — Résolution du Request ID : générer des chaînes arbitraires → vérifier que resolve() retourne la valeur inchangée si valide (<=128 chars, ASCII), ou un UUIDv4 valide sinon. Jamais de chaîne vide. Tag : `Feature: runtime-pack-openswoole, Property 9: Résolution du Request ID`
- [x] 14.5 PBT Property 19 — Sémaphore de concurrence : générer des séquences d'acquire/release avec maxConcurrentScopes=N → vérifier qu'au plus N scopes sont actifs simultanément, et que les requêtes excédentaires reçoivent 503. Tag : `Feature: runtime-pack-openswoole, Property 19: Sémaphore de concurrence`
- [x] 14.6 PBT Property 20 — ExecutionPolicy résolution : générer des dépendances avec stratégies aléatoires → vérifier que resolve() retourne la bonne stratégie, et que les dépendances inconnues retournent MustOffload. Vérifier que defaults($hookFlags) positionne guzzle=DirectCoroutineOk si SWOOLE_HOOK_CURL actif, ProbeRequired sinon. Tag : `Feature: runtime-pack-openswoole, Property 20: ExecutionPolicy résolution déterministe`
  **Implémente : Property 1, Property 4, Property 9, Property 19, Property 20 | Scope PBT V1**

## Task 15 : Tests d'intégration (serveur réel, SIGTERM, skeleton fonctionnel, V1 hardening)

- [x] 15.1 Test intégration : démarrage `async:serve` → serveur écoute sur le port configuré, GET /healthz → 200
- [x] 15.2 Test intégration : démarrage `async:run` → reload policies actives (vérifier via logs)
- [x] 15.3 Test intégration : SIGTERM → graceful shutdown avec drain, log clean shutdown
- [x] 15.4 Test intégration : double SIGTERM → arrêt forcé immédiat
- [x] 15.5 Test intégration : SIGINT en dev → arrêt immédiat propre
- [x] 15.6 Test intégration : requête > MAX_REQUEST_BODY_SIZE → rejet (vérifié via comportement OpenSwoole)
- [x] 15.7 Test intégration : skeleton `create-project` → projet fonctionnel, `async:serve` démarre, GET / → 200 `{"message":"Hello, Async PHP!"}`
- [x] 15.8 Test intégration : event-loop lag monitor → bloquer l'event loop artificiellement, vérifier lag_ms > 0 dans /readyz, lag > seuil → /readyz 503 `{"status":"event_loop_lagging","lag_ms":...}`
- [x] 15.9 Test intégration : sémaphore de concurrence → maxConcurrentScopes=2, envoyer 5 requêtes concurrentes lentes, vérifier que 2 sont traitées et 3 reçoivent 503 + Retry-After:1
- [x] 15.10 Test intégration : BlockingPool runOrRespondError → job full → 503 + Retry-After, job timeout → 504, job send failed → 502, job failed → 500
- [x] 15.11 Test intégration : BlockingPool late response cleanup → timeout un job, vérifier que la réponse IPC tardive est ignorée proprement (warning log, pas de leak)
- [x] 15.12 Test intégration : ExecutionPolicy + IoExecutor → configurer une dépendance DIRECT_COROUTINE_OK → appel direct, MUST_OFFLOAD → offload BlockingPool. Vérifier que defaults() avec/sans SWOOLE_HOOK_CURL positionne guzzle correctement
- [x] 15.13 Test intégration : ResponseFacade::end() fix → handler sans status() → log d'accès contient status_code=200 (statusCode explicitement positionné)
- [x] 15.14 Test intégration : Healthchecks Content-Type → /healthz et /readyz retournent Content-Type:application/json en plus de Cache-Control:no-store
- [x] 15.15 Test intégration : Version headers → vérifier que les headers Server et X-Powered-By sont absents (ou vides) dans les réponses HTTP. Valider le mécanisme exact OpenSwoole utilisé.
- [x] 15.16 Test intégration : BlockingPool sendToPool failure → simuler un pool down, vérifier que le pendingJob est nettoyé, métrique pool_send_failed incrémentée, BlockingPoolSendException propagée
- [x] 15.17 Test intégration : IPC framing → envoyer un payload volumineux (> 64KB), vérifier que le framing uint32 length prefix reconstitue le message complet côté reader
  **Implémente : Validation end-to-end des Requirements 1, 2, 3, 5, 6 + V1 hardening (Properties 5, 8, 16, 17, 18-21)**

## Task 16 : ExecutionPolicy + IoExecutor (politique coroutine-safe centralisée)

- [x] 16.1 Créer `ExecutionStrategy` enum (DirectCoroutineOk, MustOffload, ProbeRequired)
- [x] 16.2 Créer `ExecutionPolicy` avec `register()`, `resolve()`, `canRunDirect()`, `all()`
- [x] 16.3 Implémenter `ExecutionPolicy::defaults(int $hookFlags)` avec la matrice de compatibilité async (redis=DIRECT, file_io=DIRECT, openswoole_http=DIRECT, guzzle=DIRECT si SWOOLE_HOOK_CURL actif sinon PROBE, pdo=PROBE, ffi=OFFLOAD, etc.)
- [x] 16.4 Créer `IoExecutor` avec `run(dependency, jobName, payload, directCallable, timeout)` : route automatiquement selon la politique
- [x] 16.5 Intégrer ExecutionPolicy dans ServerBootstrap : créée au boot, passée aux handlers via DI
- [x] 16.6 Documenter la matrice de compatibilité et les stratégies dans docs/configuration.md
  **Implémente : Contrat Async — Invariant (politique d'exécution centralisée) | Property 20**

## Task 17 : BlockingPool production hardening (outbound channel, dispatcher, reader, cleanup, runOrRespondError, IPC framing, métriques réelles)

- [x] 17.1 Implémenter le channel outbound borné (capacity = maxQueueSize) et la coroutine dispatcher par worker HTTP dans `initWorker()`. Le dispatcher consomme le channel et appelle sendToPool(). En cas d'échec sendToPool : push erreur dans le pendingJob channel, incrémenter pool_send_failed.
- [x] 17.2 Implémenter la reader coroutine : une par worker HTTP dans onWorkerStart, écoute en boucle les messages IPC du pool avec framing (uint32 length prefix + reconstitution complète avant json_decode)
- [x] 17.3 Implémenter la reconnexion automatique de la reader coroutine : backoff exponentiel (100ms, 200ms, 400ms, 800ms, 1600ms), max 5 retries, log critical si échec
- [x] 17.4 Implémenter `cleanupOrphanedJobs()` : timer périodique 60s, supprime les pendingJobs dont le Channel est fermé
- [x] 17.5 Implémenter `routeResponse()` avec gestion des late responses (job_id expiré → warning log "late response for expired job")
- [x] 17.6 Créer `BlockingPoolHttpException` avec `httpStatusCode`
- [x] 17.7 Créer `BlockingPoolSendException` pour les échecs d'envoi au pool (pool down/broken socket)
- [x] 17.8 Implémenter `runOrRespondError()` : mapping standardisé Full→503+Retry-After+Content-Type, Timeout→504+Content-Type, SendFailed→502+Content-Type, RuntimeException→500+Content-Type, succès→résultat
- [x] 17.9 Implémenter `queueDepth()` = outbound channel length (vraie queue), `inflightCount()` = count(pendingJobs) (en vol), `busyWorkers()` = compteur incr/decr réel
- [x] 17.10 Implémenter le protocole IPC framé : uint32 length prefix (big-endian) + JSON payload. Pour payloads binaires : encoding base64 explicite ou champ type:"binary" + frame séparée
- [x] 17.11 Intégrer les métriques réelles : blocking_pool_busy_workers, blocking_queue_depth (outbound), blocking_inflight_count, blocking_pool_send_failed mis à jour en temps réel
  **Implémente : Contrat Async — Invariant (BlockingPool borné, reader coroutine, IPC framé) | Property 16, Property 21**

## Task 18 : ScopeRunner sémaphore de concurrence (maxConcurrentScopes) + timer ordering

- [x] 18.1 Implémenter le sémaphore dans ScopeRunner : Channel borné pré-rempli de tokens si maxConcurrentScopes > 0. Commentaire aligné : pop()=acquire, push()=release.
- [x] 18.2 Implémenter l'acquisition non-bloquante (pop timeout=0) : si vide (tous tokens acquis) → 503 immédiat + Content-Type:application/json + Retry-After:1 + métrique scope_rejected. Log warning avec `maxConcurrentScopes` (propriété stockée, pas channel.capacity).
- [x] 18.3 Implémenter le relâchement du sémaphore dans le finally de runRequest() (push token)
- [x] 18.4 Vérifier que maxConcurrentScopes=0 → pas de sémaphore (comportement illimité préservé)
- [x] 18.5 Intégrer la métrique scope_rejected_total dans MetricsCollector
- [x] 18.6 Implémenter l'ordering correct dans le timer callback : setStatusCode(408) AVANT trySend() + Content-Type:application/json
- [x] 18.7 Implémenter l'ordering correct dans le catch exception : setStatusCode(500) AVANT trySend() + Content-Type:application/json
  **Implémente : Contrat Async — Invariant (concurrence bornée par worker) | Property 17, Property 19**

## Task 19 : ResponseFacade::end() bug fix + ResponseState.hasExplicitStatusCode()

- [x] 19.1 Ajouter `hasExplicitStatusCode(): bool` à ResponseState (retourne true si setStatusCode() a été appelé)
- [x] 19.2 Modifier ResponseFacade::end() : supprimer le `if` tautologique, appeler `setStatusCode(200)` si `!hasExplicitStatusCode()`
- [x] 19.3 Vérifier que end() sans status() → statusCode=200 dans ResponseState (pas null)
- [x] 19.4 Vérifier que end() avec status(201) → statusCode=201 préservé (pas écrasé par 200)
- [x] 19.5 Vérifier que le log d'accès reflète le bon statusCode dans tous les cas
  **Implémente : Property 17 (fix statusCode tracking)**

## Task 20 : Documentation finale (docs/configuration.md complet, ADR si nécessaire)

- [x] 20.1 Compléter `docs/configuration.md` avec toutes les variables d'environnement, types, défauts, descriptions, et la table de mapping vers les settings OpenSwoole (incluant EVENT_LOOP_LAG_THRESHOLD_MS, MAX_CONCURRENT_SCOPES)
- [x] 20.2 Ajouter la section "Compatibility Matrix" dans la documentation (PHP 8.3+, OpenSwoole 22.x, Debian bookworm, amd64/arm64, RSS Linux only)
- [x] 20.3 Documenter le comportement du graceful shutdown (mécanique 503 applicatif, pas de stop accept socket)
- [x] 20.4 Documenter le WORKER_RESTART_MIN_INTERVAL et le garde-fou anti crash-loop
- [x] 20.5 Créer un ADR si des décisions structurantes ont été prises pendant l'implémentation (Debian vs Alpine, UUIDv4 vs ULID, exit(0) pour reload, sémaphore Channel, ExecutionPolicy)
- [x] 20.6 Documenter dans le README skeleton : proxy frontal obligatoire en prod + config minimale timeouts anti-slowloris (nginx: client_header_timeout/client_body_timeout, caddy: équivalent). Mention : "runtime-pack ne garantit pas read-timeout en V1".
- [x] 20.7 Mettre à jour le README racine avec les instructions d'installation, de démarrage, et les liens vers la documentation
- [x] 20.8 Documenter la matrice ExecutionPolicy (DIRECT/OFFLOAD/PROBE) et l'usage de IoExecutor dans le README skeleton section "Écrire des handlers async-safe"
- [x] 20.9 Documenter le event-loop lag monitor et le seuil configurable dans docs/configuration.md
  **Implémente : Requirements 6.3, 8.3 | DoD : docs mises à jour**
