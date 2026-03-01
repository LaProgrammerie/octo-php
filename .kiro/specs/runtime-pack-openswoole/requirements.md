# Document de Requirements — Runtime Pack OpenSwoole + Skeleton

## Introduction

Cette spec couvre la première brique fondamentale de la plateforme async PHP : le **Runtime Pack OpenSwoole** et le **Skeleton** (create-project). L'objectif est de fournir un serveur HTTP OpenSwoole prod-safe, avec les politiques opérationnelles obligatoires (healthchecks, graceful shutdown, reload policy), une image Docker (dev + prod), et un skeleton prêt à l'emploi.

Le runtime pack est un serveur HTTP OpenSwoole long-running. Le mode FPM n'est pas supporté.

Le runtime pack est le socle sur lequel toutes les autres specs (concurrency, blocking pool, observabilité, tooling) viendront se greffer.

### Non-goals (V1)

- Support FrankenPHP
- Mode FPM
- Drivers DB async natifs
- Intégration framework complète (Symfony bundle, etc.) — hors scope de cette spec
- Structured concurrency (Context/TaskGroup) — spec séparée
- BlockingPool — spec séparée
- Tooling CLI avancé (async:doctor, async:bench) — spec séparée. `async:serve` et `async:run` sont des commandes de bootstrap minimal (démarrage du serveur uniquement), pas un framework CLI.
- OTEL exporter — spec Observability V1 séparée
- Endpoint /metrics — spec Observability V1 séparée
- Protection slowloris / read timeout natif — non garanti en V1, à investiguer en V2

### Notes pour le Design

- Le choix de l'image de base Docker (Debian slim vs Alpine) sera tranché dans le Design.

## Glossaire

- **Runtime_Pack** : Package composer fournissant le bootstrap OpenSwoole, la configuration serveur, les politiques prod (reload, shutdown), et les endpoints opérationnels (healthz/readyz).
- **Skeleton** : Template de projet (create-project) qui génère une application minimale fonctionnelle utilisant le Runtime_Pack.
- **Serveur_OpenSwoole** : Instance du serveur HTTP OpenSwoole configurée et démarrée par le Runtime_Pack.
- **Healthcheck_Endpoint** : Endpoint HTTP exposant l'état de santé du processus (/healthz pour liveness, /readyz pour readiness).
- **Graceful_Shutdown** : Procédure d'arrêt contrôlé du serveur en 4 étapes : stop accept → drain connexions → attendre inflight (avec timeout hard) → stop.
- **Reload_Policy** : Ensemble de seuils configurables (MAX_REQUESTS, MAX_UPTIME, MAX_MEMORY_RSS) déclenchant un redémarrage automatique du worker pour prévenir les fuites mémoire et le state leak.
- **Worker** : Processus fils OpenSwoole traitant les requêtes HTTP.
- **Docker_Image** : Image conteneur fournissant l'environnement d'exécution complet (PHP + OpenSwoole + application).
- **Proxy_Frontal** : Reverse proxy (Caddy/Nginx) recommandé devant le Serveur_OpenSwoole pour TLS, compression, headers, et fichiers statiques.
- **Logs_JSON** : Format de logging structuré avec champs corrélés (request_id, timestamp, level, message, component).
- **Request_Id** : Identifiant unique de requête (UUIDv4 ou ULID), propagé depuis le header X-Request-Id entrant ou généré automatiquement.

## Requirements

### Requirement 1 : Bootstrap du serveur OpenSwoole

**User Story :** En tant que développeur, je veux démarrer un serveur HTTP OpenSwoole via le Runtime_Pack, afin de servir des requêtes HTTP sans configuration manuelle complexe.

#### Critères d'acceptation

1. WHEN la commande `async:serve` est exécutée, THE Serveur_OpenSwoole SHALL démarrer en mode développement sur le host et le port configurés (défaut : 0.0.0.0:8080).
2. WHEN la commande `async:run` est exécutée, THE Serveur_OpenSwoole SHALL démarrer en mode production avec les politiques de reload et de shutdown activées.
3. THE Runtime_Pack SHALL permettre la configuration du host, du port et du nombre de workers via des variables d'environnement (APP_HOST, APP_PORT, APP_WORKERS).
4. WHEN aucune variable d'environnement APP_WORKERS n'est définie en mode production, THE Runtime_Pack SHALL configurer le nombre de workers à `swoole_cpu_num()`. Les workloads IO-bound peuvent bénéficier d'un multiplicateur x2 configuré manuellement via APP_WORKERS.
5. WHEN aucune variable d'environnement APP_WORKERS n'est définie en mode développement, THE Runtime_Pack SHALL configurer le nombre de workers à 2.
6. WHEN le Serveur_OpenSwoole démarre avec succès, THE Serveur_OpenSwoole SHALL écrire un log JSON contenant le host, le port, le nombre de workers et le mode (dev/prod).

### Requirement 2 : Healthcheck endpoints

**User Story :** En tant qu'opérateur, je veux des endpoints de healthcheck standardisés, afin de pouvoir intégrer le serveur dans un orchestrateur (Kubernetes, Docker Swarm) ou un load balancer.

#### Critères d'acceptation

1. THE Serveur_OpenSwoole SHALL exposer un endpoint GET /healthz retournant un HTTP 200 avec un corps JSON `{"status":"alive"}` lorsque le processus est actif.
2. THE Serveur_OpenSwoole SHALL exposer un endpoint GET /readyz retournant un HTTP 200 avec un corps JSON `{"status":"ready"}` lorsque les conditions suivantes sont réunies : le serveur n'est pas en shutdown, les workers sont démarrés, et le timestamp `last_loop_tick_at` date de moins de 2 secondes.
3. THE Runtime_Pack SHALL maintenir un timer interne mettant à jour un timestamp `last_loop_tick_at` toutes les 250ms. THE endpoint /readyz SHALL considérer l'event loop comme non opérationnelle si `last_loop_tick_at` date de plus de 2 secondes. Le timestamp `last_loop_tick_at` est maintenu par Worker. L'endpoint /readyz reflète l'état du Worker répondant à la requête. L'architecture master/workers et la stratégie d'agrégation éventuelle seront détaillées dans le Design.
4. WHEN le Serveur_OpenSwoole est en cours de shutdown, THE endpoint /readyz SHALL retourner un HTTP 503 avec un corps JSON `{"status":"shutting_down"}`.
5. WHEN le Serveur_OpenSwoole est en cours de shutdown, THE endpoint /healthz SHALL continuer à retourner un HTTP 200 tant que le processus est actif.
6. THE Serveur_OpenSwoole SHALL traiter les requêtes /healthz et /readyz sans I/O externe, sans dépendance externe, en traitement constant O(1). En conditions nominales, la latence p95 SHOULD être inférieure à 5ms.
7. THE endpoint /readyz SHALL être strictement runtime-only : aucune vérification de dépendance externe (base de données, cache, services tiers). Les checks de dépendances seront ajoutés dans les specs futures.
8. THE Serveur_OpenSwoole SHALL inclure le header `Cache-Control: no-store` dans les réponses des endpoints /healthz et /readyz.

### Requirement 3 : Graceful shutdown

**User Story :** En tant qu'opérateur, je veux un arrêt gracieux du serveur, afin de ne perdre aucune requête en cours lors d'un déploiement ou d'un redémarrage.

#### Critères d'acceptation

1. WHEN un signal SIGTERM est reçu, THE Serveur_OpenSwoole SHALL cesser d'accepter de nouvelles connexions.
2. WHEN un signal SIGTERM est reçu, THE Serveur_OpenSwoole SHALL attendre la fin des requêtes en cours (inflight) avant de s'arrêter.
3. THE Serveur_OpenSwoole SHALL appliquer un timeout hard configurable (SHUTDOWN_TIMEOUT, défaut : 30 secondes) au-delà duquel les requêtes inflight sont interrompues et le processus s'arrête. La valeur de SHUTDOWN_TIMEOUT doit être inférieure à terminationGracePeriodSeconds dans un environnement Kubernetes.
4. WHEN le graceful shutdown commence, THE Serveur_OpenSwoole SHALL écrire un log JSON indiquant le nombre de requêtes inflight et le timeout configuré.
5. WHEN le graceful shutdown se termine, THE Serveur_OpenSwoole SHALL écrire un log JSON indiquant si l'arrêt a été complet (toutes requêtes terminées) ou forcé (timeout atteint).
6. WHEN un second signal SIGTERM est reçu pendant le shutdown, THE Serveur_OpenSwoole SHALL forcer l'arrêt immédiat.
7. WHEN un signal SIGINT est reçu en mode développement, THE Serveur_OpenSwoole SHALL effectuer un arrêt immédiat propre (fermeture des connexions actives sans attendre le drain complet).

### Requirement 4 : Reload policy

**User Story :** En tant qu'opérateur, je veux des politiques de reload automatique des workers, afin de prévenir les fuites mémoire et le state leak dans un processus long-running.

#### Critères d'acceptation

1. WHILE le Serveur_OpenSwoole fonctionne en mode production, THE Reload_Policy SHALL redémarrer un Worker lorsque le nombre de requêtes traitées par le Worker atteint la valeur MAX_REQUESTS (configurable via variable d'environnement, défaut : 10000).
2. WHILE le Serveur_OpenSwoole fonctionne en mode production, THE Reload_Policy SHALL redémarrer un Worker lorsque l'uptime du Worker atteint la valeur MAX_UPTIME (configurable via variable d'environnement, défaut : 3600 secondes).
3. WHILE le Serveur_OpenSwoole fonctionne en mode production, THE Reload_Policy SHALL redémarrer un Worker lorsque la mémoire RSS du Worker atteint la valeur MAX_MEMORY_RSS (configurable via variable d'environnement, défaut : 128 Mo). La mesure de la mémoire RSS est effectuée via `/proc/self/statm` (Linux containers en priorité).
4. WHEN un Worker est redémarré par la Reload_Policy, THE Serveur_OpenSwoole SHALL écrire un log JSON indiquant la raison du redémarrage (max_requests, max_uptime, ou max_memory_rss) et les valeurs courantes.
5. WHEN la variable d'environnement d'une politique de reload est définie à 0, THE Reload_Policy SHALL désactiver cette politique spécifique.
6. IF le fichier `/proc/self/statm` n'est pas disponible, THEN THE Reload_Policy SHALL désactiver automatiquement la politique MAX_MEMORY_RSS et écrire un log JSON de niveau warning indiquant que la mesure mémoire n'est pas disponible sur cette plateforme.
7. THE Reload_Policy SHALL redémarrer les Workers de manière gracieuse (attendre la fin de la requête en cours avant de redémarrer le Worker).
8. THE Reload_Policy SHALL garantir qu'un reload de Worker ne tue jamais une requête en cours. Le Worker termine sa requête courante avant de s'arrêter.

### Requirement 5 : Image Docker (dev + prod)

**User Story :** En tant que développeur, je veux des images Docker prêtes à l'emploi, afin de démarrer rapidement en développement et de déployer en production sans configuration manuelle.

#### Critères d'acceptation

1. THE Docker_Image SHALL fournir un stage "dev" incluant PHP, l'extension OpenSwoole, Composer, et Xdebug.
2. THE Docker_Image SHALL fournir un stage "prod" incluant PHP, l'extension OpenSwoole, OPcache activé, et les dépendances Composer sans les dépendances de développement.
3. THE Docker_Image en mode prod SHALL exécuter le Serveur_OpenSwoole en tant qu'utilisateur non-root.
4. THE Docker_Image SHALL exposer le port 8080 par défaut.
5. THE Docker_Image en mode prod SHALL définir un HEALTHCHECK Docker utilisant l'endpoint /healthz.
6. THE Docker_Image SHALL utiliser un multi-stage build pour minimiser la taille de l'image de production.

### Requirement 6 : Skeleton (create-project)

**User Story :** En tant que développeur, je veux un template de projet (create-project), afin de démarrer une nouvelle application async PHP en quelques secondes avec les bonnes pratiques intégrées.

#### Critères d'acceptation

1. WHEN un développeur exécute `composer create-project async-platform/skeleton mon-projet`, THE Skeleton SHALL générer un projet fonctionnel contenant un point d'entrée, un fichier de configuration, un Dockerfile, et un fichier docker-compose.yml.
2. THE Skeleton SHALL inclure un endpoint d'exemple GET / retournant un HTTP 200 avec un corps JSON `{"message":"Hello, Async PHP!"}`.
3. THE Skeleton SHALL inclure un fichier README.md documentant les commandes disponibles (async:serve, async:run), la configuration par variables d'environnement, l'architecture du projet, les endpoints opérationnels (/healthz, /readyz) avec leur comportement attendu, et la mention que le proxy frontal (Caddy/Nginx) est responsable de HSTS, compression, fichiers statiques — le runtime pack ne réinvente pas ces fonctionnalités.
4. THE Skeleton SHALL inclure un fichier .env.example listant toutes les variables d'environnement configurables avec leurs valeurs par défaut.
5. WHEN le Skeleton est installé et que la commande `async:serve` est exécutée sans modification, THE Serveur_OpenSwoole SHALL démarrer et répondre aux requêtes HTTP.

### Requirement 7 : Observabilité de base du runtime

**User Story :** En tant qu'opérateur, je veux une observabilité minimale du runtime dès le démarrage, afin de diagnostiquer les problèmes de performance et de stabilité.

#### Critères d'acceptation

1. THE Serveur_OpenSwoole SHALL émettre tous les logs au format JSON avec les champs minimaux : timestamp (format RFC3339 UTC), level (enum : debug, info, warning, error, critical), message (string), request_id (string, lorsque applicable), et component (string, ex : "runtime", "http") pour distinguer les sources de logs. Chaque log est émis en une seule ligne JSON (Newline-Delimited JSON / NDJSON) pour compatibilité avec les systèmes d'ingestion (ELK, Loki, etc.).
2. THE Serveur_OpenSwoole SHALL émettre le log de démarrage avec le champ component='runtime' et le mode (dev/prod).
3. WHEN une requête HTTP est traitée, THE Serveur_OpenSwoole SHALL écrire un log JSON contenant : method, path, status_code, duration_ms, et request_id.
4. THE Runtime_Pack SHALL maintenir des compteurs internes accessibles programmatiquement : requests_total (compteur), request_duration_ms (histogramme), workers_active (gauge), memory_rss_bytes (gauge par worker). Ces compteurs seront exploités par les specs futures (Observability V1, endpoint /metrics).

### Requirement 8 : Configuration et validation

**User Story :** En tant que développeur, je veux une configuration centralisée et validée au démarrage, afin d'éviter les erreurs de configuration en production.

#### Critères d'acceptation

1. WHEN le Serveur_OpenSwoole démarre, THE Runtime_Pack SHALL valider toutes les variables d'environnement de configuration et signaler les valeurs invalides via un message d'erreur explicite.
2. IF une variable d'environnement obligatoire est manquante ou invalide, THEN THE Runtime_Pack SHALL refuser de démarrer et afficher un message d'erreur listant les variables problématiques.
3. THE Runtime_Pack SHALL documenter toutes les variables d'environnement dans le fichier de référence `docs/configuration.md` avec leur type, leur valeur par défaut, et leur description.
4. WHEN le mode production est actif, THE Runtime_Pack SHALL vérifier que les politiques de reload sont configurées et émettre un avertissement si toutes les politiques sont désactivées.

### Requirement 9 : Sécurité de base du runtime

**User Story :** En tant qu'opérateur, je veux des garde-fous de sécurité de base sur le runtime, afin de limiter la surface d'attaque en production.

#### Critères d'acceptation

1. THE Serveur_OpenSwoole SHALL limiter la taille maximale du corps des requêtes HTTP (configurable via MAX_REQUEST_BODY_SIZE, défaut : 2 Mo).
2. THE Serveur_OpenSwoole SHALL limiter le nombre de connexions simultanées (configurable via MAX_CONNECTIONS, défaut : 1024).
3. THE Serveur_OpenSwoole SHALL appliquer un timeout d'exécution du handler de requête (configurable via REQUEST_HANDLER_TIMEOUT, défaut : 60 secondes). Ce timer applicatif protège contre les handlers lents. Il ne protège pas contre les clients lents (slowloris) — le parsing HTTP bas niveau est géré par OpenSwoole.
4. THE Serveur_OpenSwoole SHALL ne pas exposer d'informations de version PHP ou OpenSwoole dans les headers de réponse HTTP.
5. WHEN le mode production est actif, THE Serveur_OpenSwoole SHALL désactiver l'affichage des erreurs PHP détaillées dans les réponses HTTP.

#### Table de mapping : variables d'environnement → settings OpenSwoole

| Variable d'environnement | Setting OpenSwoole | Défaut |
|---|---|---|
| APP_HOST | `host` (paramètre `Server::__construct`) | 0.0.0.0 |
| APP_PORT | `port` (paramètre `Server::__construct`) | 8080 |
| APP_WORKERS | `worker_num` | `swoole_cpu_num()` (prod) / 2 (dev) |
| MAX_REQUEST_BODY_SIZE | `package_max_length` | 2 Mo (2097152) |
| MAX_CONNECTIONS | `max_conn` / `max_connection` (mapping exact validé en Design, source doc OpenSwoole) | 1024 |
| REQUEST_HANDLER_TIMEOUT | Timer applicatif dans le handler de requête (`Timer::after()` dans `onRequest`). Protège contre les handlers lents. Ne protège pas contre les clients lents (slowloris) — le parsing HTTP bas niveau est géré par OpenSwoole. | 60s |
| SHUTDOWN_TIMEOUT | N/A (géré applicativement) | 30s |
| MAX_REQUESTS | `max_request` | 10000 |
| MAX_UPTIME | N/A (géré applicativement) | 3600s |
| MAX_MEMORY_RSS | N/A (géré applicativement via /proc/self/statm) | 128 Mo |

### Requirement 10 : Request ID

**User Story :** En tant qu'opérateur, je veux un identifiant unique par requête propagé dans les logs et les réponses, afin de pouvoir tracer une requête de bout en bout.

#### Critères d'acceptation

1. WHEN une requête HTTP entrante contient le header X-Request-Id, THE Serveur_OpenSwoole SHALL réutiliser cette valeur comme identifiant de requête.
2. WHEN une requête HTTP entrante ne contient pas le header X-Request-Id, THE Serveur_OpenSwoole SHALL générer un identifiant unique (UUIDv4 ou ULID) comme identifiant de requête.
3. THE Serveur_OpenSwoole SHALL inclure le header X-Request-Id avec la valeur du request_id dans toutes les réponses HTTP.
4. THE Serveur_OpenSwoole SHALL inclure le request_id dans tous les logs JSON émis pendant le traitement de la requête.
5. WHEN le header X-Request-Id entrant dépasse 128 caractères ou contient des caractères non-ASCII, THE Serveur_OpenSwoole SHALL ignorer la valeur, générer un nouvel identifiant, et écrire un log JSON de niveau warning indiquant que le X-Request-Id entrant a été rejeté (sans refléter l'input brut dans le log).
