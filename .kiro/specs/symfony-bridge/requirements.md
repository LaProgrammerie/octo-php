# Document de Requirements — Symfony Bridge Suite

## 1. Introduction

Cette spec couvre la **Symfony Bridge Suite**, un ensemble cohérent de packages Composer permettant d'exécuter une application Symfony (HttpKernel) sur le runtime OpenSwoole fourni par `async-platform/runtime-pack`.

La suite est organisée en 6 packages Composer indépendants, chacun avec une responsabilité claire, permettant aux développeurs d'installer uniquement ce dont ils ont besoin.

### Problème

Exécuter une application Symfony dans un processus long-running OpenSwoole pose des défis spécifiques :

- Symfony est conçu pour un cycle request/response unique (FPM). Les services stateful (EntityManager, caches statiques, sessions) fuient entre requêtes.
- Le mapping HTTP entre OpenSwoole et HttpFoundation n'est pas trivial (headers multi-valués, fichiers uploadés, cookies, encodage).
- La stabilité en production exige un reset systématique de l'état entre requêtes, une surveillance mémoire, et des garde-fous contre les leaks.
- Un package monolithique force l'installation de dépendances inutiles (Messenger, OTEL, WebSocket) pour des projets qui n'en ont pas besoin.

### Proposition

Une suite de packages Composer modulaire qui :

1. **Core (`symfony-bridge`)** : Fournit le callable handler pour `ServerBootstrap::run()`, la conversion HTTP, le cycle de vie long-running, l'observabilité de base, et le streaming natif (StreamedResponse, StreamedJsonResponse, SSE).
2. **Bundle (`symfony-bundle`)** : Auto-configuration Symfony via un bundle et une recipe Flex.
3. **Messenger (`symfony-messenger`)** : Transport Messenger in-process via channels OpenSwoole.
4. **Realtime (`symfony-realtime`)** : WebSocket et helpers SSE avancés (event formatting, keep-alive, reconnection ID).
5. **OTEL (`symfony-otel`)** : Export OpenTelemetry (traces + métriques) coroutine-safe.
6. **Full (`symfony-bridge-full`)** : Meta-package installant tout.

### Bénéfices de l'architecture modulaire

- **Installation minimale** : seul le core est requis, les extensions sont opt-in.
- **Arbre de dépendances réduit** : pas de `symfony/messenger`, `open-telemetry/sdk`, etc. si non utilisés.
- **Testabilité** : chaque package a sa propre suite de tests isolée.
- **Évolutivité** : ajout de nouveaux packages sans impacter le core.
- **Versioning indépendant** : SemVer coordonné par release — tous les packages partagent la même version lors de chaque release.

### Scope

Ce document couvre la release complète de la Symfony Bridge Suite. Toutes les fonctionnalités de l'ancien package monolithique sont préservées et redistribuées dans les packages appropriés.

### Notes pour le Design

- Le choix entre HttpFoundation directe vs PSR-7 (via `symfony/psr-http-message-bridge`) sera tranché dans le Design (ADR).
- L'interaction avec `ResponseFacade` vs `OpenSwoole\Http\Response` brute sera précisée dans le Design.
- La stratégie de sizing des channels Messenger et la backpressure seront détaillées dans le Design.
- La configuration du mode WebSocket server OpenSwoole sera détaillée dans le Design.
- La configuration du batch processor OTEL (taille du batch, intervalle d'export, exporter async) sera détaillée dans le Design.

---

## 2. Architecture Multi-Packages

### Diagramme de dépendances

```
async-platform/runtime-pack (indépendant, aucune dépendance Symfony)
        │
        ▼
async-platform/symfony-bridge (core)
        │
        ├──────────────────┬──────────────────┬──────────────────┐
        ▼                  ▼                  ▼                  ▼
  symfony-bundle    symfony-messenger   symfony-realtime    symfony-otel
        │
        ▼
  (auto-détecte messenger, realtime, otel si installés)

async-platform/symfony-bridge-full (meta: dépend de tous)
```

### Description des packages

| Package | Responsabilité |
|---------|---------------|
| `async-platform/symfony-bridge` | Core : HttpKernelAdapter, conversion HTTP, streaming natif, reset, anti-leak, métriques de base, error handling, RequestIdProcessor, Profiler-friendly lifecycle (terminate/reset ordering compatible avec Symfony Profiler) |
| `async-platform/symfony-bundle` | Bundle Symfony + recipe Flex, auto-configuration, auto-tag ResetHookInterface, wiring Profiler (routes, config, documentation dev) |
| `async-platform/symfony-messenger` | Transport Messenger in-process via channels OpenSwoole bornés |
| `async-platform/symfony-realtime` | RealtimeServerAdapter (callable routant HTTP/WS), WebSocketHandler, WebSocketContext, helpers SSE avancés (event formatting, keep-alive, reconnection) |
| `async-platform/symfony-otel` | Traces OTEL (span/request, child spans), métriques OTEL, batch processor coroutine-safe |
| `async-platform/symfony-bridge-full` | Meta-package : dépend de tous les packages ci-dessus, aucun code |

### Principes d'architecture

- Le **core bridge** fonctionne seul, sans bundle, sans Messenger, sans OTEL, sans WebSocket.
- Le **bundle** est optionnel : le bridge reste un callable handler pur utilisable depuis n'importe quel script.
- Chaque package d'extension dépend du core bridge mais PAS du bundle.
- Le bundle auto-détecte les packages d'extension installés et les configure automatiquement.
- Le **runtime-pack** reste indépendant de Symfony : aucune dépendance Composer vers des packages Symfony.

---

## 3. Prérequis Runtime Pack

Le runtime pack (`async-platform/runtime-pack`) fournit les interfaces et composants que la bridge suite consomme. Le runtime pack DOIT rester indépendant de Symfony.

### Interfaces consommées par la bridge suite

| Interface / Composant | Rôle | Consommé par |
|----------------------|------|-------------|
| `ServerBootstrap::run(callable $handler)` | Point d'entrée serveur, accepte un callable handler | Core bridge (HttpKernelAdapter) |
| `ResponseFacade` | Wrapper autour de `OpenSwoole\Http\Response` avec protection double-send | Core bridge (Response_Converter) |
| `ResponseFacade::write(string $content)` | Streaming partiel (chunks) | Core bridge (StreamedResponse, SSE) |
| `ResponseFacade::end(string $content)` | Terminaison de la réponse (une seule fois) | Core bridge (Response_Converter) |
| `ResponseState` | Garantit qu'une seule réponse HTTP est envoyée par requête | Core bridge (double-send protection) |
| `ResponseState::isSent()` | Vérifie si la réponse a déjà été envoyée (408 timeout, 503 shutdown) | Core bridge (skip response) |
| `MetricsCollector` | Collecteur de métriques runtime, étendu par le bridge | Core bridge, Messenger, Realtime, OTEL |
| `JsonLogger` | Logger JSON structuré avec support `component` | Core bridge |
| `ScopeRunner` | Exécution du handler avec deadline et cancellation | Core bridge |
| `BlockingPool` | Pool borné pour I/O bloquantes (Doctrine legacy) | Documenté par Core bridge |
| `ExecutionPolicy` | Politique d'exécution (timeout, retry) | Core bridge |
| `ReloadPolicy` | Politique de reload worker (max requests, max memory, max uptime) | Core bridge (fournit métriques) |

---

## 4. Glossaire

- **Symfony_Bridge_Suite** : Ensemble des 6 packages Composer constituant l'intégration Symfony ↔ OpenSwoole.
- **Symfony_Bridge** : Package core (`async-platform/symfony-bridge`) fournissant l'adaptateur entre le runtime pack OpenSwoole et une application Symfony HttpKernel.
- **HttpKernel_Adapter** : Classe principale du core bridge, implémentant le callable handler attendu par `ServerBootstrap::run()`. Convertit les requêtes/réponses et délègue au HttpKernel Symfony.
- **HttpKernel** : Interface Symfony (`Symfony\Component\HttpKernel\HttpKernelInterface`) traitant une `Request` HttpFoundation et retournant une `Response`.
- **HttpFoundation_Request** : Objet `Symfony\Component\HttpFoundation\Request` représentant une requête HTTP côté Symfony.
- **HttpFoundation_Response** : Objet `Symfony\Component\HttpFoundation\Response` représentant une réponse HTTP côté Symfony.
- **OpenSwoole_Request** : Objet `OpenSwoole\Http\Request` représentant une requête HTTP côté OpenSwoole.
- **OpenSwoole_Response** : Objet `OpenSwoole\Http\Response` représentant une réponse HTTP côté OpenSwoole.
- **Request_Converter** : Composant responsable de la conversion OpenSwoole_Request → HttpFoundation_Request.
- **Response_Converter** : Composant responsable de la conversion HttpFoundation_Response → OpenSwoole_Response, incluant le streaming natif via ResponseFacade.
- **Reset_Manager** : Composant responsable du reset d'état entre requêtes (services stateful, caches, hooks de reset).
- **ResetInterface** : Interface Symfony (`Symfony\Contracts\Service\ResetInterface`) permettant de réinitialiser un service entre requêtes.
- **ResetHookInterface** : Interface fournie par le core bridge permettant d'enregistrer des hooks de reset custom exécutés entre chaque requête. Chaque hook implémente une méthode `reset(): void`.
- **DoctrineResetHook** : Implémentation optionnelle de `ResetHookInterface` fournie comme exemple/suggestion pour réinitialiser l'EntityManager Doctrine et gérer les transactions orphelines. Non requise par le bridge.
- **RequestIdProcessor** : Classe fournie par le core bridge implémentant `Monolog\Processor\ProcessorInterface`, qui lit `_request_id` depuis les attributs de la requête courante et l'ajoute à tous les enregistrements de log Monolog.
- **Runtime_Pack** : Package `async-platform/runtime-pack` fournissant le serveur OpenSwoole, les politiques prod, et l'observabilité de base.
- **ServerBootstrap** : Classe du runtime pack (`AsyncPlatform\RuntimePack\ServerBootstrap`) point d'entrée pour démarrer le serveur.
- **ResponseFacade** : Wrapper du runtime pack autour de `OpenSwoole\Http\Response` avec protection double-send et support streaming (`write()`/`end()`).
- **ResponseState** : Objet du runtime pack garantissant qu'une seule réponse HTTP est envoyée par requête (`trySend()`, `isSent()`).
- **MetricsCollector** : Collecteur de métriques du runtime pack, étendu par les packages de la bridge suite avec des métriques spécifiques.
- **LoggerInterface** : Interface PSR-3 (`Psr\Log\LoggerInterface`) utilisée par le bridge pour émettre ses logs. Si l'implémentation fournie est le JsonLogger du runtime pack, le bridge positionne `component="symfony_bridge"` dans le contexte.
- **AsyncPlatformBundle** : Bundle Symfony fourni par le package `symfony-bundle` pour l'auto-configuration des services.
- **OpenSwooleTransport** : Transport Symfony Messenger fourni par le package `symfony-messenger`, utilisant les coroutines et channels OpenSwoole pour le message passing in-process.
- **WebSocketHandler** : Interface fournie par le package `symfony-realtime` pour gérer les connexions WebSocket.
- **WebSocketContext** : DTO fourni par le package `symfony-realtime` contenant les informations de connexion WebSocket (connection ID, request_id, headers, méthode send).
- **RealtimeServerAdapter** : Callable fourni par le package `symfony-realtime`, compatible avec `ServerBootstrap::run()`, qui route les requêtes HTTP vers le HttpKernelAdapter du core bridge et les requêtes WebSocket upgrade vers le WebSocketHandler enregistré.
- **OTEL_Span** : Span OpenTelemetry créé par le package `symfony-otel` pour chaque requête Symfony, avec attributs HTTP et Symfony.
- **ScopeRunner** : Composant du runtime pack exécutant le handler avec deadline et cancellation.
- **BlockingPool** : Pool borné du runtime pack pour isoler les I/O bloquantes (legacy Doctrine, etc.).

---

## 5. Requirements

---

### Package : `async-platform/symfony-bridge` (Core)

---

### Requirement 1 : Bootstrap et intégration avec le Runtime Pack

**User Story :** En tant que développeur Symfony, je veux exécuter mon application Symfony sur le runtime OpenSwoole via un callable handler, afin de bénéficier des performances async sans modifier mon code applicatif.

#### Critères d'acceptation

1. THE Symfony_Bridge SHALL fournir une classe HttpKernel_Adapter qui produit un callable compatible avec `ServerBootstrap::run($handler)`.
2. WHEN le HttpKernel_Adapter est instancié, THE Symfony_Bridge SHALL accepter un HttpKernel Symfony et un `Psr\Log\LoggerInterface` comme dépendances obligatoires. IF le LoggerInterface fourni est une instance de JsonLogger du runtime pack, THEN THE Symfony_Bridge SHALL positionner `component="symfony_bridge"` dans le contexte de log.
3. THE Symfony_Bridge SHALL permettre l'instanciation du HttpKernel_Adapter sans imposer de commande CLI custom : le bridge est un callable, utilisable depuis n'importe quel script de bootstrap.
4. WHEN `ServerBootstrap::run()` invoque le callable handler avec une OpenSwoole_Request et une OpenSwoole_Response, THE HttpKernel_Adapter SHALL convertir la requête, déléguer au HttpKernel Symfony, convertir la réponse, et envoyer la réponse via OpenSwoole_Response.
5. THE Symfony_Bridge SHALL dépendre de `symfony/http-kernel` et `symfony/http-foundation` via Composer, avec une contrainte de compatibilité couvrant Symfony 6.4 LTS et Symfony 7.x.
6. THE Symfony_Bridge SHALL dépendre de `psr/log` via Composer pour le LoggerInterface. La dépendance vers `async-platform/runtime-pack` reste requise pour les interfaces d'observabilité (MetricsCollector) et l'intégration serveur.
7. THE Runtime_Pack SHALL rester indépendant de Symfony : aucune dépendance Composer vers des packages Symfony dans le runtime pack.

### Requirement 2 : Conversion HTTP — OpenSwoole Request → Symfony HttpFoundation Request

**User Story :** En tant que développeur Symfony, je veux que les requêtes OpenSwoole soient fidèlement converties en objets HttpFoundation, afin que mon code Symfony reçoive des données identiques à celles d'un environnement FPM.

#### Critères d'acceptation

1. WHEN une OpenSwoole_Request est reçue, THE Request_Converter SHALL créer une HttpFoundation_Request avec les paramètres query string (`$request->get`), le corps de la requête (`$request->rawContent()`), les cookies (`$request->cookie`), les fichiers uploadés (`$request->files`), et les variables serveur reconstruites.
2. THE Request_Converter SHALL mapper les headers HTTP de la OpenSwoole_Request vers les headers de la HttpFoundation_Request, en préservant les headers multi-valués (ex : `Accept`, `Cache-Control`).
3. THE Request_Converter SHALL reconstruire le tableau `$_SERVER` équivalent à partir des données OpenSwoole : `REQUEST_METHOD`, `REQUEST_URI`, `QUERY_STRING`, `CONTENT_TYPE`, `CONTENT_LENGTH`, `SERVER_PROTOCOL`, `SERVER_NAME`, `SERVER_PORT`, `REMOTE_ADDR`, `REMOTE_PORT`, et les headers HTTP préfixés `HTTP_`.
4. THE Request_Converter SHALL mapper les fichiers uploadés OpenSwoole (`$request->files`) vers des objets `UploadedFile` HttpFoundation, en préservant le nom original, le type MIME, la taille, et le code d'erreur.
5. WHEN le corps de la requête est au format `application/x-www-form-urlencoded` ou `multipart/form-data`, THE Request_Converter SHALL parser le corps et peupler les paramètres POST de la HttpFoundation_Request (`$request->post` pour les données parsées par OpenSwoole).
6. THE Request_Converter SHALL propager le header `X-Request-Id` de la OpenSwoole_Request vers la HttpFoundation_Request (via les headers et les attributs de requête).
7. WHEN la OpenSwoole_Request contient un corps JSON (`Content-Type: application/json`), THE Request_Converter SHALL rendre le corps brut accessible via `$httpFoundationRequest->getContent()` sans altération.
8. THE Request_Converter SHALL gérer correctement l'encodage des URI contenant des caractères UTF-8 ou percent-encoded.
9. WHEN la OpenSwoole_Request contient des cookies, THE Request_Converter SHALL les mapper vers les cookies de la HttpFoundation_Request en préservant les noms et valeurs.
10. THE Request_Converter SHALL construire toutes les données de la HttpFoundation_Request exclusivement à partir des propriétés de la OpenSwoole_Request. THE Request_Converter SHALL ne pas lire ni dépendre des superglobales PHP (`$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`).
11. THE Request_Converter SHALL ne pas modifier les superglobales PHP. Aucune écriture dans `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, ou `$_FILES` ne sera effectuée par le bridge.
12. THE Symfony_Bridge SHALL documenter que toute bibliothèque utilisant `Request::createFromGlobals()` n'est PAS supportée en mode long-running et que les développeurs doivent utiliser l'objet Request injecté par le bridge.
13. THE Symfony_Bridge SHALL documenter que l'état global PHP (timezone, locale, `mb_internal_encoding`) doit être configuré une seule fois au boot du worker et ne doit pas être modifié par requête.

### Requirement 3 : Conversion HTTP — Symfony HttpFoundation Response → OpenSwoole Response + Streaming natif

**User Story :** En tant que développeur Symfony, je veux que les réponses Symfony soient fidèlement converties en réponses OpenSwoole, avec support du streaming natif (StreamedResponse, StreamedJsonResponse, SSE via StreamedResponse), afin que les clients reçoivent des réponses HTTP correctes y compris pour les réponses streamées.

#### Critères d'acceptation

1. WHEN le HttpKernel retourne une HttpFoundation_Response, THE Response_Converter SHALL écrire le status code HTTP sur la OpenSwoole_Response.
2. THE Response_Converter SHALL mapper tous les headers de la HttpFoundation_Response vers la OpenSwoole_Response, en gérant les headers multi-valués (ex : `Set-Cookie` avec plusieurs valeurs).
3. THE Response_Converter SHALL mapper les cookies de la HttpFoundation_Response (via `$response->headers->getCookies()`) vers la OpenSwoole_Response en utilisant `$swooleResponse->cookie()`, en préservant : nom, valeur, expiration, path, domain, secure, httpOnly, sameSite.
4. THE Response_Converter SHALL écrire le corps de la HttpFoundation_Response sur la OpenSwoole_Response via `ResponseFacade::end($content)`.
5. THE Response_Converter SHALL supprimer les headers `Server` et `X-Powered-By` de la réponse finale pour ne pas exposer d'informations de version.
6. WHEN la HttpFoundation_Response est une `BinaryFileResponse`, THE Response_Converter SHALL envoyer le fichier via `$swooleResponse->sendfile()` pour une performance optimale.
7. WHEN la HttpFoundation_Response contient un header `Content-Length`, THE Response_Converter SHALL le préserver dans la réponse OpenSwoole.
8. FOR ALL HttpFoundation_Response valides, la conversion vers OpenSwoole_Response puis la re-lecture des headers et du status code SHALL produire des valeurs équivalentes aux originales (propriété round-trip sur les métadonnées).
9. WHEN la HttpFoundation_Response est une `StreamedResponse` (et non une `BinaryFileResponse`), THE Response_Converter SHALL utiliser `ResponseFacade::write()` pour streamer les chunks au fur et à mesure que le callback les produit, puis appeler `ResponseFacade::end('')` quand le callback se termine.
10. THE Response_Converter SHALL positionner les headers appropriés avant de démarrer le stream (status code, Content-Type). IF `Content-Length` est absent ET le streaming est actif, THEN THE Response_Converter SHALL déléguer la gestion du chunked encoding à OpenSwoole (qui gère `Transfer-Encoding: chunked` automatiquement) plutôt que de forcer le header manuellement, afin d'éviter une double déclaration.
11. IF le callback d'une `StreamedResponse` lève une exception, THEN THE Response_Converter SHALL écrire un log de niveau error incluant le request_id et le message d'exception, et terminer la réponse avec le contenu déjà envoyé.
12. THE Response_Converter SHALL supporter `StreamedJsonResponse` (Symfony 6.3+) via le même mécanisme de streaming natif.
13. WHEN la HttpFoundation_Response est une `StreamedResponse` avec `Content-Type: text/event-stream` (SSE), THE Response_Converter SHALL flusher chaque chunk d'événement immédiatement vers le client via `ResponseFacade::write()`.
14. WHEN la HttpFoundation_Response a un `Content-Type: text/event-stream`, THE Response_Converter SHALL désactiver la compression HTTP et le buffering de sortie sur la OpenSwoole_Response avant de démarrer le stream, afin de garantir que chaque chunk SSE est envoyé immédiatement au client sans mise en tampon.

### Requirement 4 : Cycle de vie long-running — Reset d'état et ordering

**User Story :** En tant qu'opérateur, je veux que l'état de l'application Symfony soit réinitialisé entre chaque requête selon un ordre strict, afin d'éviter les fuites de données entre requêtes et les memory leaks dans un processus long-running.

#### Critères d'acceptation

1. THE HttpKernel_Adapter SHALL respecter l'ordre strict suivant pour chaque requête : handle → obtenir la Response → écrire la réponse sur OpenSwoole_Response → `end()` → `kernel->terminate($request, $response)` → reset. Cet ordre est invariant.
2. BEFORE d'écrire la réponse sur la OpenSwoole_Response, THE HttpKernel_Adapter SHALL vérifier si la réponse a déjà été envoyée (via le ResponseState/ResponseFacade du runtime pack, ex : 408 timeout par ScopeRunner ou 503 par graceful shutdown).
3. IF la réponse a déjà été envoyée par le runtime pack, THEN THE HttpKernel_Adapter SHALL ne pas écrire la réponse sur la OpenSwoole_Response, écrire un log de niveau warning avec le request_id et la raison du skip, et passer directement à terminate + reset.
4. WHEN une requête est terminée, THE Reset_Manager SHALL réinitialiser l'état Symfony selon la stratégie suivante (dans cet ordre de priorité) :
   - IF le Kernel implémente `Symfony\Contracts\Service\ResetInterface`, THEN appeler `$kernel->reset()`.
   - ELSE IF le service `services_resetter` existe dans le container Symfony, THEN appeler `$container->get('services_resetter')->reset()`.
   - ELSE effectuer un reset best-effort (documenté) et écrire un log de niveau warning indiquant qu'aucune stratégie de reset complète n'a été trouvée.
5. THE Reset_Manager SHALL exécuter le reset dans un bloc `finally` : le reset DOIT être exécuté même si le handler a levé une exception.
6. THE Reset_Manager SHALL fournir une interface `ResetHookInterface` permettant d'enregistrer des hooks de reset custom exécutés après le reset Symfony principal. Chaque hook implémente une méthode `reset(): void`.
7. IF Doctrine est présent ET un `DoctrineResetHook` est enregistré, THEN THE Reset_Manager SHALL exécuter le hook Doctrine qui appelle `$em->clear()` et vérifie que la connexion est dans un état propre (pas de transaction ouverte).
8. IF une transaction Doctrine est restée ouverte après le traitement d'une requête ET un `DoctrineResetHook` est enregistré, THEN THE DoctrineResetHook SHALL effectuer un rollback, écrire un log de niveau warning avec le request_id, et réinitialiser la connexion.
9. THE Symfony_Bridge SHALL ne PAS dépendre de Doctrine : le `DoctrineResetHook` est fourni comme implémentation suggérée/optionnelle. Le bridge fournit l'interface `ResetHookInterface`, le reset Doctrine est opt-in.
10. THE Reset_Manager SHALL vider les caches statiques connus comme problématiques en long-running (ex : `Symfony\Component\HttpFoundation\Request::setTrustedProxies()` state). THE Reset_Manager SHALL ne PAS muter l'état statique interne de Symfony qui n'est pas officiellement supporté pour le reset (ex : `Kernel::$booted`), car cela peut devenir fragile entre versions Symfony. Le reboot complet du kernel (R4.15–R4.18) est le fallback robuste pour les cas où le reset standard ne suffit pas.
11. WHEN le reset est effectué, THE Reset_Manager SHALL mesurer la durée du reset et l'exposer comme métrique (`symfony_reset_duration_ms`).
12. THE Reset_Manager SHALL écrire un log de niveau debug après chaque reset, incluant le request_id et la durée du reset.
13. IF le reset échoue (exception levée par un service ResetInterface ou un ResetHook), THEN THE Reset_Manager SHALL écrire un log de niveau error avec le request_id et le message d'erreur, et continuer le traitement des requêtes suivantes sans interrompre le worker.
14. THE Reset_Manager SHALL mesurer la durée du reset et écrire un log de niveau warning si la durée dépasse un seuil configurable (`ASYNC_PLATFORM_SYMFONY_RESET_WARNING_MS`, défaut : 50 ms). Le log SHALL inclure le request_id, la durée du reset (`reset_duration_ms`), et le seuil configuré.
15. THE Symfony_Bridge SHALL supporter une politique optionnelle de reboot du kernel via la variable d'environnement `ASYNC_PLATFORM_SYMFONY_KERNEL_REBOOT_EVERY` (défaut : 0 = désactivé).
16. WHEN la politique de reboot est activée ET le compteur de requêtes atteint la valeur configurée, THE Symfony_Bridge SHALL rebooter le kernel Symfony (`$kernel->shutdown()` puis `$kernel->boot()`) au lieu d'un simple reset. Le reboot ne tue PAS le processus worker — il reconstruit uniquement le kernel et le container.
17. WHEN un reboot du kernel est effectué, THE Symfony_Bridge SHALL écrire un log de niveau info incluant le request_id et le nombre de requêtes traitées depuis le dernier reboot.
18. WHEN un reboot du kernel est déclenché, THE HttpKernel_Adapter SHALL reconstruire ses références internes vers les nouvelles instances du Kernel et du Container. Les objets internes de l'adapter (ResetManager, RequestIdProcessor, hooks enregistrés) DOIVENT pointer vers le nouveau container après le reboot, afin d'éviter toute incohérence entre l'adapter et le kernel reconstruit.

### Requirement 5 : Anti-leak et surveillance mémoire

**User Story :** En tant qu'opérateur, je veux des garde-fous contre les memory leaks dans le processus long-running, afin de garantir la stabilité en production sur de longues durées.

#### Critères d'acceptation

1. THE Symfony_Bridge SHALL compter le nombre de requêtes traitées par le worker et exposer ce compteur dans les logs et métriques.
2. WHILE le worker traite des requêtes, THE Symfony_Bridge SHALL mesurer la mémoire RSS après chaque reset et exposer cette valeur comme métrique (`memory_rss_after_reset_bytes`). Cette mesure est une heuristique : la RSS inclut la mémoire partagée entre processus et ne constitue pas une preuve formelle de leak. La documentation SHALL préciser cette limitation et recommander l'utilisation conjointe de la RSS et du delta mémoire entre requêtes pour diagnostiquer les leaks.
3. WHEN la mémoire RSS après reset dépasse un seuil configurable (variable d'environnement `ASYNC_PLATFORM_SYMFONY_MEMORY_WARNING_THRESHOLD`, défaut : 100 Mo), THE Symfony_Bridge SHALL écrire un log de niveau warning incluant le request_id, la mémoire RSS courante, le seuil, et le nombre de requêtes traitées.
4. THE Symfony_Bridge SHALL documenter les recommandations anti-leak dans le README : services à déclarer comme `ResetInterface`, patterns à éviter (singletons statiques, caches globaux), configuration Doctrine recommandée, et enregistrement de `ResetHookInterface` custom.
5. THE Symfony_Bridge SHALL déléguer la décision de reload du worker au runtime pack (ReloadPolicy) : le bridge ne tue jamais le worker lui-même, il fournit les métriques et les warnings.

### Requirement 6 : Propagation du Request-ID et observabilité

**User Story :** En tant qu'opérateur, je veux que le request_id du runtime pack soit propagé dans l'application Symfony et les logs du bridge, afin de tracer une requête de bout en bout.

#### Critères d'acceptation

1. WHEN une requête est traitée par le bridge, THE HttpKernel_Adapter SHALL extraire le `X-Request-Id` de la OpenSwoole_Request (déjà résolu par le runtime pack) et le rendre disponible dans la HttpFoundation_Request comme attribut (`_request_id`) et comme header.
2. THE Symfony_Bridge SHALL émettre tous ses logs via le `Psr\Log\LoggerInterface` fourni avec le champ de contexte `component="symfony_bridge"`.
3. WHEN une requête est traitée, THE Symfony_Bridge SHALL émettre un log contenant : request_id, status_code, duration_ms, et exception_class (si une exception a été levée).
4. THE Symfony_Bridge SHALL exposer les métriques suivantes via le MetricsCollector du runtime pack :
   - `symfony_requests_total` (compteur) : nombre total de requêtes traitées par le bridge.
   - `symfony_request_duration_ms` (histogramme) : durée de traitement par le HttpKernel (hors conversion HTTP).
   - `symfony_exceptions_total` (compteur) : nombre total d'exceptions levées par le HttpKernel.
   - `symfony_reset_duration_ms` (histogramme) : durée du reset entre requêtes.
5. THE Symfony_Bridge SHALL propager le request_id dans le contexte Symfony pour que les logs applicatifs (Monolog) puissent l'inclure (via un attribut de requête ou le RequestIdProcessor fourni par le bridge).
6. THE Symfony_Bridge SHALL fournir une classe `RequestIdProcessor` implémentant `Monolog\Processor\ProcessorInterface`. Le processor lit `_request_id` depuis les attributs de la requête courante et l'ajoute à tous les enregistrements de log Monolog.
7. THE Symfony_Bridge SHALL documenter comment enregistrer le `RequestIdProcessor` dans la configuration Monolog Symfony (service tag `monolog.processor`), afin de rendre la propagation du request_id dans les logs applicatifs triviale.

### Requirement 7 : Gestion des erreurs — Prod vs Dev

**User Story :** En tant que développeur, je veux un comportement d'erreur adapté au mode (dev/prod), afin de faciliter le debug en développement sans exposer de détails sensibles en production.

#### Critères d'acceptation

1. WHEN une exception est levée par le HttpKernel en mode production, THE HttpKernel_Adapter SHALL retourner une réponse HTTP 500 avec un corps JSON générique `{"error":"Internal Server Error"}` sans stacktrace ni détails de l'exception.
2. WHEN une exception est levée par le HttpKernel en mode développement, THE HttpKernel_Adapter SHALL laisser Symfony gérer l'affichage de l'erreur (page d'erreur Symfony avec stacktrace) si le HttpKernel produit une réponse d'erreur.
3. IF le HttpKernel lève une exception et ne produit pas de réponse, THEN THE HttpKernel_Adapter SHALL écrire un log de niveau error avec le request_id, la classe de l'exception, et le message, puis retourner une réponse HTTP 500.
4. THE HttpKernel_Adapter SHALL ne jamais laisser une exception non catchée remonter au runtime pack : toutes les exceptions du HttpKernel sont interceptées et converties en réponse HTTP.
5. WHEN une exception est interceptée, THE Symfony_Bridge SHALL incrémenter le compteur `symfony_exceptions_total` dans le MetricsCollector.

### Requirement 8 : Concurrence et async-safety

**User Story :** En tant que développeur, je veux des règles claires sur ce qui peut s'exécuter dans la coroutine vs ce qui doit passer par le BlockingPool, afin d'éviter de bloquer l'event loop.

#### Critères d'acceptation

1. THE Symfony_Bridge SHALL documenter que le HttpKernel Symfony s'exécute dans la coroutine de requête OpenSwoole et que les I/O hookées (réseau, fichiers) yield automatiquement à l'event loop.
2. THE Symfony_Bridge SHALL documenter les recommandations pour les bundles/libs courants :
   - Doctrine : utiliser via IoExecutor/BlockingPool si les hooks PDO ne sont pas validés par preuve d'intégration. Configurer le pool de connexions pour le long-running.
   - Guzzle/HttpClient : coroutine-safe si `SWOOLE_HOOK_CURL` actif (vérifié au boot par le runtime pack).
   - Filesystem : coroutine-safe via `SWOOLE_HOOK_FILE`.
   - Sessions : ne pas utiliser les sessions fichier natives PHP en long-running (state leak). Recommander un handler de session externe (Redis, DB).
3. THE Symfony_Bridge SHALL interagir avec le ScopeRunner du runtime pack pour bénéficier des deadlines et de la cancellation : le callable handler est exécuté dans le scope géré par ScopeRunner.
4. THE Symfony_Bridge SHALL respecter le timeout du runtime pack (`REQUEST_HANDLER_TIMEOUT`) : si la deadline est atteinte, le ScopeRunner envoie un 408 et le bridge ne tente pas d'envoyer une seconde réponse.

### Requirement 9 : Intégration Profiler et WebDebugToolbar

**User Story :** En tant que développeur Symfony, je veux que le Profiler et la WebDebugToolbar fonctionnent en mode dev sur le runtime OpenSwoole, afin de debugger mon application comme en FPM.

#### Critères d'acceptation

1. THE Symfony_Bridge SHALL garantir le fonctionnement correct du Symfony Profiler en mode dev en réinitialisant les data collectors du profiler entre les requêtes (via ResetInterface/services_resetter).
2. THE Symfony_Bridge SHALL garantir que la WebDebugToolbar est injectée correctement dans les réponses HTML en laissant le `WebDebugToolbarListener` de Symfony opérer normalement sur la HttpFoundation Response avant la conversion vers OpenSwoole Response.
3. THE Symfony_Bridge SHALL désactiver automatiquement l'intégration Profiler en mode production (aucun overhead de performance).
4. THE Symfony_Bridge SHALL garantir un ordering de lifecycle compatible avec le Profiler Symfony : terminate (qui déclenche la collecte profiler) est appelé AVANT le reset des services.

---

### Package : `async-platform/symfony-bundle`

---

### Requirement 10 : Bundle Symfony et Flex Recipe

**User Story :** En tant que développeur Symfony, je veux un bundle Symfony et une recipe Flex pour intégrer le bridge automatiquement dans mon application, afin de réduire le boilerplate de configuration.

#### Critères d'acceptation

1. THE AsyncPlatformBundle SHALL auto-enregistrer les services du core bridge dans le container Symfony (HttpKernelAdapter, ResetManager, RequestIdProcessor, intégration MetricsCollector).
2. THE AsyncPlatformBundle SHALL auto-tagger les services implémentant `ResetHookInterface` pour un enregistrement automatique auprès du Reset_Manager.
3. THE AsyncPlatformBundle SHALL fournir une section de configuration (`async_platform`) avec des clés pour toutes les variables d'environnement du bridge (`memory_warning_threshold`, `reset_warning_ms`, `kernel_reboot_every`). Le bundle mappe la configuration YAML vers les variables d'environnement `ASYNC_PLATFORM_SYMFONY_*`.
4. THE AsyncPlatformBundle SHALL auto-enregistrer le `RequestIdProcessor` comme processeur Monolog si Monolog est disponible.
5. THE AsyncPlatformBundle SHALL auto-détecter et configurer les packages optionnels de la suite si installés :
   - IF `async-platform/symfony-messenger` est installé, THEN THE AsyncPlatformBundle SHALL enregistrer le OpenSwooleTransport et sa factory dans le container.
   - IF `async-platform/symfony-realtime` est installé, THEN THE AsyncPlatformBundle SHALL enregistrer le WebSocketHandler et les helpers SSE dans le container.
   - IF `async-platform/symfony-otel` est installé (ce qui implique que `open-telemetry/sdk` est présent), THEN THE AsyncPlatformBundle SHALL configurer les services OTEL (span processor, metrics exporter) dans le container.
6. THE AsyncPlatformBundle SHALL fournir une recipe Flex qui :
   - Crée un fichier `config/packages/async_platform.yaml` avec des valeurs par défaut sensées.
   - Crée un script de bootstrap `bin/async-server.php` qui boot le kernel et appelle `ServerBootstrap::run()`.
   - Ajoute les variables d'environnement `ASYNC_PLATFORM_*` au fichier `.env`.
7. THE AsyncPlatformBundle SHALL être optionnel : le core bridge DOIT rester utilisable sans le bundle (mode callable handler pur).
8. THE AsyncPlatformBundle SHALL dépendre de `async-platform/symfony-bridge`, `symfony/framework-bundle`, et `symfony/dependency-injection` via Composer.
9. THE AsyncPlatformBundle SHALL documenter les limitations connues du Profiler en mode long-running : le stockage in-memory du profiler n'est PAS supporté en mode long-running (les données seraient perdues ou accumulées entre requêtes). Le stockage du profiler DOIT utiliser le filesystem ou SQLite. Les données profiler des requêtes précédentes sont accessibles via la route standard `/_profiler`.
10. THE AsyncPlatformBundle SHALL garantir que les routes `_profiler` et `_wdt` sont servies correctement à travers le bridge (assets statiques, requêtes XHR pour les données de la toolbar).

---

### Package : `async-platform/symfony-messenger`

---

### Requirement 11 : Transport Messenger OpenSwoole

**User Story :** En tant que développeur Symfony, je veux utiliser Symfony Messenger avec un transport async natif OpenSwoole, afin de traiter des messages en arrière-plan sans dépendre de RabbitMQ/Redis pour les cas simples.

#### Critères d'acceptation

1. THE OpenSwooleTransport SHALL implémenter `TransportInterface` de Symfony Messenger.
2. THE OpenSwooleTransport SHALL utiliser les coroutines et channels OpenSwoole pour le message passing in-process (channel borné avec capacité configurable, défaut : 100).
3. THE OpenSwooleTransport SHALL supporter les opérations `send()`, `get()`, `ack()`, `reject()`.
4. THE OpenSwooleTransport SHALL respecter la backpressure : si le channel est plein, `send()` SHALL bloquer la coroutine (yield) jusqu'à ce qu'un espace soit disponible ou qu'un timeout configurable soit atteint.
5. IF le timeout de send est atteint, THEN THE OpenSwooleTransport SHALL lever une `TransportException` avec un message explicite.
6. THE OpenSwooleTransport SHALL exposer des métriques via MetricsCollector : `messenger_messages_sent_total`, `messenger_messages_consumed_total`, `messenger_channel_size` (gauge).
7. THE OpenSwooleTransport SHALL être enregistré comme transport DSN (`openswoole://default`) dans la configuration Messenger.
8. THE OpenSwooleTransport SHALL documenter que ce transport est in-process uniquement (non distribué) et que les messages sont perdus au restart du worker. Pour le messaging durable, recommander les transports externes (AMQP, Redis, Doctrine).
9. THE OpenSwooleTransport SHALL supporter un nombre configurable de coroutines consommatrices (`ASYNC_PLATFORM_SYMFONY_MESSENGER_CONSUMERS`, défaut : 1) spawnées via TaskGroup avec structured concurrency.
10. THE OpenSwooleTransport consumers SHALL respecter les deadlines et la cancellation via ScopeRunner.
11. THE OpenSwooleTransport consumers SHALL être démarrés au boot du worker et annulés proprement lors du shutdown du worker via la cancellation du ScopeRunner. Les consumers ne DOIVENT PAS survivre au cycle de vie du worker (pas de coroutines zombies).
12. THE OpenSwooleTransport SHALL dépendre de `async-platform/symfony-bridge` et `symfony/messenger` via Composer. THE OpenSwooleTransport SHALL ne PAS dépendre du bundle.
13. THE OpenSwooleTransport SHALL dépendre de `async-platform/runtime-pack` pour l'accès aux channels OpenSwoole et au TaskGroup.
14. THE OpenSwooleTransport channel SHALL être isolé par worker : chaque worker OpenSwoole possède son propre channel. Les messages ne sont PAS partagés entre workers.
15. THE OpenSwooleTransport SHALL documenter que les mécanismes standard de retry et failure transport de Symfony Messenger s'appliquent normalement (middleware retry, failure transport configurable). Le transport in-process ne fournit pas de mécanisme de retry custom.
16. THE OpenSwooleTransport SHALL documenter que les messages non consommés au moment du restart du worker sont perdus. Pour les messages critiques, recommander un failure transport durable (Doctrine, Redis).

---

### Package : `async-platform/symfony-realtime`

---

### Requirement 12 : Support WebSocket

**User Story :** En tant que développeur, je veux pouvoir servir des WebSocket depuis mon application Symfony sur le runtime OpenSwoole, afin de supporter les cas d'usage temps réel bidirectionnels.

#### Critères d'acceptation

1. THE Symfony_Realtime SHALL fournir une interface `WebSocketHandler` qui peut être enregistrée pour gérer les connexions WebSocket.
2. THE Symfony_Realtime SHALL fournir un `RealtimeServerAdapter` (callable) compatible avec `ServerBootstrap::run($handler)` qui route les requêtes HTTP vers le HttpKernelAdapter du core bridge et les requêtes WebSocket upgrade vers le WebSocketHandler enregistré.
3. THE RealtimeServerAdapter SHALL détecter les requêtes d'upgrade WebSocket via les headers `Upgrade: websocket` et `Connection: Upgrade`, et les déléguer au WebSocketHandler sans passer par le HttpKernel Symfony.
4. THE WebSocketHandler SHALL recevoir la frame WebSocket OpenSwoole directement (pas de conversion HttpFoundation pour les frames WS).
5. THE Symfony_Realtime SHALL fournir un DTO `WebSocketContext` contenant : connection ID, request_id, headers de la requête, et une méthode `send(string $data): void`.
6. THE WebSocketHandler SHALL respecter les deadlines et la cancellation : chaque connexion WebSocket a un max lifetime configurable (`ASYNC_PLATFORM_SYMFONY_WS_MAX_LIFETIME_SECONDS`, défaut : 3600).
7. THE Symfony_Realtime SHALL exposer des métriques WebSocket via MetricsCollector : `ws_connections_active` (gauge), `ws_messages_received_total`, `ws_messages_sent_total`.
8. THE Symfony_Realtime SHALL documenter que le support WebSocket nécessite le mode WebSocket server d'OpenSwoole et fournir les instructions de configuration.
9. THE Symfony_Realtime SHALL dépendre de `async-platform/symfony-bridge` et `async-platform/runtime-pack` via Composer. THE Symfony_Realtime SHALL ne PAS dépendre du bundle.

### Requirement 13 : Helpers SSE avancés

**User Story :** En tant que développeur, je veux des helpers pour formater les événements SSE (event type, data, id, retry, keep-alive), afin de ne pas réimplémenter le protocole SSE manuellement.

#### Critères d'acceptation

1. THE Symfony_Realtime SHALL fournir un helper `SseEvent` permettant de formater un événement SSE conforme à la spécification W3C (champs : `event`, `data`, `id`, `retry`).
2. THE SseEvent SHALL produire une chaîne formatée avec les lignes `event:`, `data:`, `id:`, `retry:` suivies d'un double `\n` terminal, conformément à la spécification SSE.
3. THE Symfony_Realtime SHALL fournir un helper `SseStream` qui encapsule l'envoi d'événements SSE via `ResponseFacade::write()` avec :
   - Envoi de keep-alive périodique (commentaire SSE `: keep-alive\n\n`) configurable (défaut : 15 secondes).
   - Support du champ `Last-Event-ID` pour la reconnexion client.
4. FOR ALL SseEvent valides, le formatage puis le parsing du texte produit SHALL restituer les champs originaux (propriété round-trip sur le formatage SSE).
5. THE Symfony_Realtime SHALL documenter la différence entre le SSE basique (StreamedResponse dans le core bridge, R3) et les helpers SSE avancés (ce package) : le core bridge fournit le mécanisme de streaming, ce package fournit le protocole SSE structuré.

---

### Package : `async-platform/symfony-otel`

---

### Requirement 14 : Export OpenTelemetry (traces + métriques)

**User Story :** En tant qu'opérateur, je veux un export OpenTelemetry complet (traces + métriques) depuis le bridge Symfony, afin d'avoir une observabilité end-to-end dans mon infrastructure.

#### Critères d'acceptation

1. THE Symfony_Otel SHALL fournir une intégration OTEL trace qui crée un span pour chaque requête Symfony traitée par le bridge, avec les attributs : `http.method`, `http.url`, `http.status_code`, `http.request_id`, `symfony.route`, `symfony.controller`.
2. THE Symfony_Otel SHALL propager le trace context entrant (headers W3C Trace Context : `traceparent`, `tracestate`) depuis la OpenSwoole_Request vers la HttpFoundation_Request et vers le OTEL_Span.
3. THE Symfony_Otel SHALL créer des child spans pour : HttpKernel handle, Response conversion, Reset/Terminate phase.
4. THE Symfony_Otel SHALL démarrer le root span AVANT l'appel à `HttpKernel::handle()` et le terminer APRÈS la phase de Reset/Terminate. Le root span couvre l'intégralité du cycle de vie de la requête dans le bridge. IF une exception se produit avant la création des child spans, THEN le root span SHALL capturer l'exception et se terminer correctement (pas de span partiel ou orphelin).
5. THE Symfony_Otel SHALL exporter des métriques OTEL qui reflètent les métriques du MetricsCollector : `symfony_requests_total`, `symfony_request_duration_ms`, `symfony_exceptions_total`, `symfony_reset_duration_ms`.
6. THE Symfony_Otel SHALL supporter la configuration de l'endpoint de l'exporter OTEL via `OTEL_EXPORTER_OTLP_ENDPOINT` (variable d'environnement standard OTEL).
7. THE Symfony_Otel SHALL utiliser un batch span processor coroutine-safe (export async via coroutines OpenSwoole, sans bloquer l'event loop).
8. THE Symfony_Otel SHALL dépendre de `async-platform/symfony-bridge`, `open-telemetry/sdk`, et `open-telemetry/exporter-otlp` via Composer. THE Symfony_Otel SHALL ne PAS dépendre du bundle. Le SDK est une dépendance `require` du package : l'aspect optionnel se gère au niveau de la suite (on n'installe pas `symfony-otel` si on ne veut pas d'OTEL).
9. THE Symfony_Otel SHALL être configurable via `OTEL_EXPORTER_OTLP_ENDPOINT` (standard OTEL) et ne PAS introduire de variables d'environnement custom pour la configuration OTEL.

---

### Package : `async-platform/symfony-bridge-full` (Meta-package)

---

### Requirement 15 : Meta-package Full

**User Story :** En tant que développeur, je veux un meta-package qui installe toute la suite Symfony Bridge d'un coup, afin de ne pas gérer les dépendances individuellement quand je veux tout.

#### Critères d'acceptation

1. THE Symfony_Bridge_Full SHALL être un meta-package Composer (`async-platform/symfony-bridge-full`) sans code source.
2. THE Symfony_Bridge_Full SHALL dépendre de tous les packages de la suite avec des versions exactes testées (ex : `1.4.0`), garantissant que l'ensemble installé correspond à une combinaison validée par la CI.
3. THE Symfony_Bridge_Full SHALL fournir un `composer.json` avec uniquement les dépendances vers les autres packages de la suite (versions exactes), sans autoload ni code.
4. THE Symfony_Bridge_Full SHALL documenter dans son README qu'il est un raccourci d'installation et que les packages individuels peuvent être installés séparément pour un arbre de dépendances réduit.

---

### Cross-Package : Tests

---

### Requirement 16 : Tests par package

**User Story :** En tant que développeur, je veux une suite de tests isolée par package, couvrant le mapping HTTP, l'intégration Symfony, la stabilité long-running, et toutes les intégrations avancées, afin de garantir la fiabilité de chaque package indépendamment.

#### Critères d'acceptation — Package `symfony-bridge` (Core)

1. THE Symfony_Bridge SHALL inclure des tests unitaires couvrant la conversion OpenSwoole_Request → HttpFoundation_Request : méthode HTTP, URI, query string, headers (simples et multi-valués), corps (form-data, JSON, raw), fichiers uploadés, cookies, variables serveur, et request_id.
2. THE Symfony_Bridge SHALL inclure des tests unitaires couvrant la conversion HttpFoundation_Response → OpenSwoole_Response : status code, headers (simples et multi-valués), cookies (avec tous les attributs), corps, et suppression des headers sensibles.
3. THE Symfony_Bridge SHALL inclure des tests d'intégration démarrant un mini HttpKernel Symfony, envoyant une requête GET / via le bridge, et vérifiant que la réponse HTTP 200 est correcte.
4. THE Symfony_Bridge SHALL inclure un test long-running envoyant au moins 1000 requêtes séquentielles au bridge, vérifiant :
   - Que le reset est appelé après chaque requête.
   - Qu'il n'y a pas de croissance mémoire anormale (heuristique : la mémoire RSS après la requête 1000 ne dépasse pas 2x la mémoire RSS après la requête 10).
   - Que les réponses restent correctes (status 200, corps attendu).
5. THE Symfony_Bridge SHALL inclure des tests unitaires pour le Reset_Manager : vérifier que `kernel->terminate()` est appelé, que les services `ResetInterface` sont réinitialisés, que les `ResetHookInterface` enregistrés sont exécutés, et que les erreurs de reset sont loguées sans interrompre le worker.
6. THE Symfony_Bridge SHALL inclure des tests pour les edge cases HTTP : headers vides, corps vide, fichiers uploadés de taille 0, cookies avec caractères spéciaux, URI avec caractères UTF-8, requêtes sans Content-Type.
7. FOR ALL HttpFoundation_Request valides construites à partir d'une OpenSwoole_Request, la conversion vers HttpFoundation puis la relecture des champs (method, uri, headers, query, cookies) SHALL produire des valeurs équivalentes aux données OpenSwoole originales (propriété round-trip sur le mapping request).
8. THE Symfony_Bridge SHALL inclure des tests unitaires vérifiant que le bridge ne lit ni ne modifie les superglobales PHP (`$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`).
9. THE Symfony_Bridge SHALL inclure des tests de streaming natif pour `StreamedResponse` : vérification que `ResponseFacade::write()` est appelé pour chaque chunk, que les événements SSE sont flushés immédiatement, que le streaming de gros volumes fonctionne, et que les exceptions dans le callback sont gérées correctement (log + fin de réponse).
10. THE Symfony_Bridge SHALL inclure des tests unitaires vérifiant le comportement de double-send protection : quand la réponse a déjà été envoyée par le runtime pack, le bridge skip l'écriture et procède au terminate + reset.
11. THE Symfony_Bridge SHALL inclure des tests pour l'intégration Profiler : injection de la toolbar dans les réponses HTML, collecte des données profiler, reset des data collectors entre requêtes.

#### Critères d'acceptation — Package `symfony-bundle`

12. THE AsyncPlatformBundle SHALL inclure des tests vérifiant que les services sont auto-enregistrés (HttpKernelAdapter, ResetManager, RequestIdProcessor, MetricsCollector), que la configuration `async_platform` est chargée, et que la structure de la recipe Flex est correcte.
13. THE AsyncPlatformBundle SHALL inclure des tests vérifiant l'auto-détection des packages optionnels : quand `symfony-messenger` est installé, le transport est enregistré ; quand il ne l'est pas, aucune erreur.

#### Critères d'acceptation — Package `symfony-messenger`

14. THE OpenSwooleTransport SHALL inclure des tests pour : send/get/ack/reject, backpressure (channel plein → coroutine bloquée), timeout de send (TransportException), et exposition des métriques.

#### Critères d'acceptation — Package `symfony-realtime`

15. THE Symfony_Realtime SHALL inclure des tests pour le WebSocket : détection de l'upgrade WebSocket par le RealtimeServerAdapter, délégation au WebSocketHandler, routage HTTP vers le HttpKernelAdapter, cycle de vie de la connexion (ouverture, frames, fermeture, max lifetime).
16. THE Symfony_Realtime SHALL inclure des tests pour les helpers SSE : formatage correct des événements (event, data, id, retry), keep-alive, et propriété round-trip sur le formatage.

#### Critères d'acceptation — Package `symfony-otel`

17. THE Symfony_Otel SHALL inclure des tests pour l'intégration OTEL : création de spans avec les attributs corrects, propagation du trace context (W3C), création des child spans (HttpKernel handle, Response conversion, Reset phase).
18. THE Symfony_Otel SHALL inclure un test vérifiant que l'absence du package `symfony-otel` dans une installation ne provoque aucune erreur dans le bundle (auto-détection gracieuse).

---

### Cross-Package : Packaging et structure du repository

---

### Requirement 17 : Packaging et structure monorepo

**User Story :** En tant que développeur, je veux des packages Composer bien structurés dans un monorepo avec une documentation claire, afin de pouvoir contribuer et intégrer les packages dans mon application Symfony rapidement.

#### Critères d'acceptation

1. THE Symfony_Bridge_Suite SHALL être structurée dans le monorepo sous `packages/` avec les répertoires suivants :
   - `packages/symfony-bridge/` (core)
   - `packages/symfony-bundle/`
   - `packages/symfony-messenger/`
   - `packages/symfony-realtime/`
   - `packages/symfony-otel/`
   - `packages/symfony-bridge-full/`
2. EACH package SHALL contenir les répertoires `src/`, `tests/`, et `docs/` à sa racine.
3. EACH package SHALL fournir son propre `composer.json` avec le nom, les dépendances, l'autoload PSR-4, et la contrainte PHP `>=8.3`.
4. EACH package SHALL fournir son propre `README.md` documentant : installation, usage, configuration, limitations.
5. THE Symfony_Bridge (core) SHALL fournir un README.md documentant :
   - L'installation via Composer.
   - Un exemple de bootstrap minimal (code PHP pour instancier le kernel et démarrer le serveur via `ServerBootstrap::run()`).
   - La matrice de compatibilité (Symfony 6.4 LTS, Symfony 7.x, PHP 8.3+).
   - Les variables d'environnement spécifiques au bridge (`ASYNC_PLATFORM_SYMFONY_MEMORY_WARNING_THRESHOLD`, `ASYNC_PLATFORM_SYMFONY_RESET_WARNING_MS`, `ASYNC_PLATFORM_SYMFONY_KERNEL_REBOOT_EVERY`).
   - Les recommandations anti-leak et les services à déclarer comme `ResetInterface`.
   - Les recommandations de concurrence (Doctrine, Guzzle, sessions).
   - L'incompatibilité avec `Request::createFromGlobals()` et les superglobales en mode long-running.
   - Les recommandations sur l'état global PHP (timezone, locale, `mb_internal_encoding`) à configurer au boot uniquement.
   - L'enregistrement du `RequestIdProcessor` dans Monolog.
   - L'enregistrement de `ResetHookInterface` custom (ex : `DoctrineResetHook`).
   - La documentation du streaming natif (`StreamedResponse`, `StreamedJsonResponse`, SSE basique).
   - La documentation de l'intégration Profiler/WebDebugToolbar en mode dev.

---

## 6. Matrice de Dépendances Composer

> **Convention de nommage** : les variables d'environnement du runtime pack utilisent le préfixe `ASYNC_PLATFORM_*`. Les variables spécifiques à la suite Symfony utilisent le préfixe `ASYNC_PLATFORM_SYMFONY_*`. Le bundle mappe la configuration YAML vers ces variables d'environnement.

| Package | `require` | `suggest` | `require-dev` |
|---------|-----------|-----------|---------------|
| `async-platform/symfony-bridge` | `symfony/http-kernel` (^6.4\|^7.0), `symfony/http-foundation` (^6.4\|^7.0), `psr/log` (^3.0), `async-platform/runtime-pack` | `monolog/monolog`, `doctrine/orm` | `phpunit/phpunit`, `symfony/framework-bundle` |
| `async-platform/symfony-bundle` | `async-platform/symfony-bridge`, `symfony/framework-bundle` (^6.4\|^7.0), `symfony/dependency-injection` (^6.4\|^7.0) | `async-platform/symfony-messenger`, `async-platform/symfony-realtime`, `async-platform/symfony-otel` | `phpunit/phpunit` |
| `async-platform/symfony-messenger` | `async-platform/symfony-bridge`, `async-platform/runtime-pack`, `symfony/messenger` (^6.4\|^7.0) | — | `phpunit/phpunit` |
| `async-platform/symfony-realtime` | `async-platform/symfony-bridge`, `async-platform/runtime-pack` | — | `phpunit/phpunit` |
| `async-platform/symfony-otel` | `async-platform/symfony-bridge`, `open-telemetry/sdk` (^1.0), `open-telemetry/exporter-otlp` (^1.0) | — | `phpunit/phpunit` |
| `async-platform/symfony-bridge-full` | `async-platform/symfony-bridge`, `async-platform/symfony-bundle`, `async-platform/symfony-messenger`, `async-platform/symfony-realtime`, `async-platform/symfony-otel` | — | — |

---

## 7. Stratégie de Versioning

- **SemVer coordonné par release** : tous les packages de la suite partagent exactement la même version (major.minor.patch) lors de chaque release.
- Chaque release de la suite est testée comme un ensemble cohérent.
- Les packages peuvent recevoir des patch releases indépendantes pour des bugfixes isolés, mais les releases mineures et majeures sont coordonnées.
- Le meta-package `symfony-bridge-full` pin la version exacte de chaque package (ex : `1.4.0`).

---

## 8. Stratégie de Publication

- Tous les packages vivent dans le monorepo sous `packages/`.
- Un outil de split (ex : `splitsh-lite`) publie chaque package vers son repo Composer individuel.
- La CI exécute les tests de tous les packages à chaque PR.
- Chaque package a sa propre matrice de tests (PHP versions, Symfony versions).
- Le tag de release est coordonné : un script tag tous les packages modifiés avec leur version respective.

---

## 9. Migration depuis le modèle monolithique

Pour les utilisateurs qui avaient le package monolithique `async-platform/symfony-bridge` :

- **Tout installer** : `composer require async-platform/symfony-bridge-full` — installe tous les packages, comportement identique à l'ancien monolithe.
- **Installation minimale** : `composer require async-platform/symfony-bridge` — core uniquement (HTTP, reset, anti-leak, métriques de base, streaming, Profiler).
- **Ajout progressif** : installer les packages d'extension au besoin (`symfony-bundle`, `symfony-messenger`, `symfony-realtime`, `symfony-otel`).

Le core bridge conserve le même namespace et les mêmes classes publiques. La migration est transparente pour le code applicatif existant.

---

## 10. Distribution des ADR

| ADR | Package | Chemin |
|-----|---------|--------|
| ADR-001 : HttpFoundation vs PSR-7 | Core bridge | `packages/symfony-bridge/docs/adr-001-httpfoundation-vs-psr7.md` |
| ADR-002 : Stratégie de reset | Core bridge | `packages/symfony-bridge/docs/adr-002-reset-strategy.md` |
| ADR-003 : Transport Messenger in-process | Messenger | `packages/symfony-messenger/docs/adr-003-messenger-transport.md` |
| ADR-004 : Intégration WebSocket | Realtime | `packages/symfony-realtime/docs/adr-004-websocket-integration.md` |
| ADR-005 : Architecture multi-packages | Root | `docs/adr-005-multi-package-architecture.md` |
