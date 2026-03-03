# octo-php/symfony-bridge

Core Symfony HttpKernel adapter pour la plateforme async PHP — exécute une application Symfony sur le runtime OpenSwoole via `ServerBootstrap::run()`.

## Installation

```bash
composer require octo-php/symfony-bridge
```

## Compatibilité

| Dépendance | Versions supportées |
|---|---|
| PHP | >= 8.3 |
| Symfony HttpKernel | ^6.4 LTS, ^7.0 |
| Symfony HttpFoundation | ^6.4 LTS, ^7.0 |
| octo-php/runtime-pack | ^0.1 |

## Bootstrap minimal

```php
<?php

declare(strict_types=1);

use App\Kernel;
use Octo\RuntimePack\ServerBootstrap;
use Octo\SymfonyBridge\HttpKernelAdapter;

require_once __DIR__ . '/vendor/autoload.php';

$kernel = new Kernel('prod', false);
$kernel->boot();

$handler = new HttpKernelAdapter(
    kernel: $kernel,
    logger: $kernel->getContainer()->get('logger'),
);

ServerBootstrap::run(
    appHandler: $handler,
    production: true,
);
```

Le bridge est un callable handler pur : aucune commande CLI custom n'est imposée. Pour une intégration automatique via bundle et recipe Flex, voir [octo-php/symfony-bundle](../symfony-bundle/).

## Variables d'environnement

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `OCTOP_SYMFONY_MEMORY_WARNING_THRESHOLD` | int (bytes) | `104857600` (100 Mo) | Seuil RSS au-delà duquel un warning est émis après chaque reset |
| `OCTOP_SYMFONY_RESET_WARNING_MS` | int (ms) | `50` | Seuil de durée de reset au-delà duquel un warning est émis |
| `OCTOP_SYMFONY_KERNEL_REBOOT_EVERY` | int | `0` (désactivé) | Nombre de requêtes avant un reboot complet du kernel (`shutdown()` + `boot()`) |

## Cycle de vie d'une requête

Séquence invariante pour chaque requête :

1. Extraire `X-Request-Id` depuis la requête OpenSwoole
2. Convertir OpenSwoole Request → HttpFoundation Request
3. Vérifier `ResponseState.isSent()` (skip si 408/503 déjà envoyé par le runtime)
4. `HttpKernel::handle()` → obtenir la Response Symfony
5. Convertir HttpFoundation Response → OpenSwoole Response (via `ResponseFacade`)
6. `$kernel->terminate($request, $response)` (collecte Profiler ici)
7. `ResetManager::reset()` (reset services + hooks custom)
8. Métriques + vérification kernel reboot

## Métriques exposées

| Métrique | Type | Description |
|---|---|---|
| `symfony_requests_total` | counter | Nombre total de requêtes traitées |
| `symfony_request_duration_ms` | histogram | Durée de traitement par le HttpKernel |
| `symfony_exceptions_total` | counter | Nombre d'exceptions levées par le HttpKernel |
| `symfony_reset_duration_ms` | histogram | Durée du reset entre requêtes |
| `memory_rss_after_reset_bytes` | gauge | Mémoire RSS après chaque reset |

La RSS inclut la mémoire partagée entre processus et ne constitue pas une preuve formelle de leak. Utilisez conjointement la RSS et le delta mémoire entre requêtes pour diagnostiquer les leaks.

## Streaming natif

Le bridge supporte nativement le streaming via `ResponseFacade::write()`, sans buffering intermédiaire.

### StreamedResponse

```php
use Symfony\Component\HttpFoundation\StreamedResponse;

return new StreamedResponse(function () {
    echo "chunk 1\n";
    flush();
    echo "chunk 2\n";
});
```

Le callback est intercepté via `ob_start()` et chaque chunk est redirigé vers `ResponseFacade::write()`. Si le callback lève une exception, elle est loguée et la réponse est terminée avec le contenu déjà envoyé.

### StreamedJsonResponse

`StreamedJsonResponse` (Symfony 6.3+) est supporté via le même mécanisme de streaming natif.

### SSE basique (Server-Sent Events)

```php
use Symfony\Component\HttpFoundation\StreamedResponse;

return new StreamedResponse(function () {
    echo "data: hello\n\n";
    flush();
    echo "data: world\n\n";
}, 200, [
    'Content-Type' => 'text/event-stream',
    'Cache-Control' => 'no-cache',
]);
```

Quand `Content-Type: text/event-stream` est détecté, le bridge désactive automatiquement la compression HTTP et le buffering (`X-Accel-Buffering: no`, `Cache-Control: no-cache`). Chaque chunk est flushé immédiatement.

Pour des helpers SSE avancés (formatage W3C, keep-alive, reconnection ID), voir [octo-php/symfony-realtime](../symfony-realtime/).

## Recommandations anti-leak

Dans un processus long-running, les services stateful fuient entre requêtes si ils ne sont pas réinitialisés.

### Services à déclarer comme `ResetInterface`

Tout service qui accumule de l'état entre requêtes doit implémenter `Symfony\Contracts\Service\ResetInterface` :

- Caches en mémoire (ArrayAdapter, etc.)
- Repositories avec cache interne
- Services avec compteurs ou buffers

### Patterns à éviter

- **Singletons statiques** : les propriétés `static` persistent entre requêtes. Préférer l'injection de dépendances.
- **Caches globaux** : `$_SESSION`, variables globales, registres statiques.
- **État dans les constructeurs** : les services sont instanciés une seule fois. Ne pas stocker de données par requête dans les propriétés d'instance sans reset.

### Configuration Doctrine recommandée

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        # Désactiver le second-level cache en long-running
        second_level_cache:
            enabled: false
```

Enregistrer un `DoctrineResetHook` pour nettoyer l'EntityManager :

```php
use Octo\SymfonyBridge\DoctrineResetHook;

// Le hook appelle $em->clear() et rollback les transactions orphelines
$resetManager->addHook(new DoctrineResetHook($entityManager, $logger));
```

Avec le bundle, les services implémentant `ResetHookInterface` sont auto-taggés et injectés automatiquement dans le `ResetManager`.

### Kernel reboot (fallback robuste)

Si le reset standard ne suffit pas (leaks dans des services non-resettables), activez le reboot périodique :

```bash
OCTOP_SYMFONY_KERNEL_REBOOT_EVERY=1000
```

Le kernel est reconstruit (`shutdown()` + `boot()`) tous les N requêtes. Le worker n'est pas tué — seul le kernel et le container sont reconstruits.

## Concurrence et async-safety

Le `HttpKernel` Symfony s'exécute dans la coroutine de requête OpenSwoole. Les I/O hookées (réseau, fichiers) yield automatiquement à l'event loop.

### Doctrine / PDO

Utiliser via `IoExecutor` / `BlockingPool` si les hooks PDO ne sont pas validés. Configurer le pool de connexions pour le long-running :

```php
use Octo\RuntimePack\IoExecutor;

$result = IoExecutor::run(function () use ($repository) {
    return $repository->findAll();
});
```

### Guzzle / HttpClient

Coroutine-safe si `SWOOLE_HOOK_CURL` est actif (vérifié au boot par le runtime pack). Aucune action requise.

### Filesystem

Coroutine-safe via `SWOOLE_HOOK_FILE`. Aucune action requise.

### Sessions

Ne pas utiliser les sessions fichier natives PHP en long-running (state leak entre requêtes). Utiliser un handler de session externe :

- Redis (`RedisSessionHandler`)
- Base de données (`PdoSessionHandler`)

## Incompatibilités

### `Request::createFromGlobals()`

`Request::createFromGlobals()` lit les superglobales PHP (`$_SERVER`, `$_GET`, `$_POST`, etc.) qui ne sont **pas** mises à jour par OpenSwoole entre les requêtes. Toute bibliothèque utilisant `createFromGlobals()` n'est **pas supportée** en mode long-running.

Utilisez toujours l'objet `Request` injecté par le bridge.

### Superglobales PHP

Le bridge ne lit ni ne modifie les superglobales PHP. Toutes les données sont extraites exclusivement de l'objet `OpenSwoole\Http\Request`.

### État global PHP

`timezone`, `locale`, `mb_internal_encoding` doivent être configurés **une seule fois au boot du worker** et ne doivent pas être modifiés par requête :

```php
// Dans bin/async-server.php, AVANT ServerBootstrap::run()
date_default_timezone_set('UTC');
setlocale(LC_ALL, 'fr_FR.UTF-8');
mb_internal_encoding('UTF-8');
```

## RequestIdProcessor (Monolog)

Le bridge fournit un `RequestIdProcessor` qui propage le `request_id` dans tous les logs Monolog.

### Enregistrement manuel

```yaml
# config/services.yaml
services:
    Octo\SymfonyBridge\RequestIdProcessor:
        tags:
            - { name: monolog.processor }
```

### Enregistrement automatique

Avec le bundle `octo-php/symfony-bundle`, le `RequestIdProcessor` est auto-enregistré comme processeur Monolog si Monolog est disponible.

## ResetHookInterface custom

Pour enregistrer un hook de reset custom exécuté après le reset Symfony principal :

```php
use Octo\SymfonyBridge\ResetHookInterface;

final class MyCustomResetHook implements ResetHookInterface
{
    public function reset(): void
    {
        // Nettoyer l'état custom entre requêtes
    }
}
```

Chaque hook est exécuté dans un `try/catch` : un hook qui échoue ne bloque pas les suivants. L'erreur est loguée.

Avec le bundle, les services implémentant `ResetHookInterface` sont auto-taggés via le `ResetHookCompilerPass`.

## Intégration Profiler / WebDebugToolbar

En mode dev (`APP_DEBUG=true`), le Profiler et la WebDebugToolbar Symfony fonctionnent normalement :

- Le `WebDebugToolbarListener` injecte la toolbar dans les réponses HTML avant la conversion vers OpenSwoole
- `kernel->terminate()` est appelé **avant** le reset, permettant au Profiler de collecter les données
- Les data collectors sont réinitialisés entre les requêtes via `ResetInterface`
- Les routes `/_profiler` et `/_wdt` sont servies correctement

Le Profiler est automatiquement désactivé en mode production (aucun overhead).

## Gestion des erreurs

- **Production** : exception → HTTP 500 `{"error":"Internal Server Error"}` sans stacktrace
- **Développement** : exception → page d'erreur Symfony avec stacktrace complète

Aucune exception ne remonte au runtime pack. Toutes sont interceptées et converties en réponse HTTP.

## Packages complémentaires

| Package | Description |
|---|---|
| [symfony-bundle](../symfony-bundle/) | Auto-configuration Symfony, recipe Flex |
| [symfony-messenger](../symfony-messenger/) | Transport Messenger in-process via channels OpenSwoole |
| [symfony-realtime](../symfony-realtime/) | WebSocket + helpers SSE avancés |
| [symfony-otel](../symfony-otel/) | Export OpenTelemetry (traces + métriques) |
| [symfony-bridge-full](../symfony-bridge-full/) | Meta-package installant toute la suite |

## Licence

MIT
