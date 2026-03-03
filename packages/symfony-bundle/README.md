# octo-php/symfony-bundle

Bundle Symfony pour la plateforme async PHP — auto-configuration des services, recipe Flex, auto-détection des packages optionnels.

## Installation

```bash
composer require octo-php/symfony-bundle
```

Si Symfony Flex est activé, la recipe crée automatiquement :
- `config/packages/octo.yaml` — configuration par défaut
- `bin/async-server.php` — script de bootstrap
- Variables d'environnement dans `.env`

### Enregistrement manuel (sans Flex)

```php
// config/bundles.php
return [
    // ...
    Octo\SymfonyBundle\OctoBundle::class => ['all' => true],
];
```

## Configuration YAML

```yaml
# config/packages/octo.yaml
octo:
    # Seuil RSS en bytes pour warning mémoire (défaut: 100 Mo)
    memory_warning_threshold: 104857600

    # Seuil durée reset en ms pour warning (défaut: 50)
    reset_warning_ms: 50

    # Reboot kernel tous les N requêtes (0 = désactivé)
    kernel_reboot_every: 0

    # Configuration Messenger (si octo-php/symfony-messenger installé)
    messenger:
        channel_capacity: 100
        consumers: 1
        send_timeout: 5.0

    # Configuration Realtime (si octo-php/symfony-realtime installé)
    realtime:
        ws_max_lifetime_seconds: 3600

    # Configuration OTEL (si octo-php/symfony-otel installé)
    otel:
        enabled: true
```

La configuration YAML est mappée vers les variables d'environnement `OCTOP_SYMFONY_*`.

## Services auto-enregistrés

Le bundle enregistre automatiquement dans le container Symfony :

- `HttpKernelAdapter` — callable handler pour `ServerBootstrap::run()`
- `ResetManager` — gestion du reset entre requêtes
- `RequestIdProcessor` — processeur Monolog (auto-enregistré si Monolog disponible)
- `MetricsBridge` — pont vers le `MetricsCollector` du runtime pack

## Auto-tagging ResetHookInterface

Les services implémentant `Octo\SymfonyBridge\ResetHookInterface` sont automatiquement taggés et injectés dans le `ResetManager` via le `ResetHookCompilerPass`.

```php
// Aucune configuration manuelle requise — le bundle détecte et enregistre les hooks
final class MyResetHook implements ResetHookInterface
{
    public function reset(): void { /* ... */ }
}
```

## Auto-détection des packages optionnels

Le bundle détecte automatiquement les packages de la suite installés via `class_exists()` :

| Package installé | Effet |
|---|---|
| `octo-php/symfony-messenger` | Transport `OpenSwooleTransport` + factory enregistrés |
| `octo-php/symfony-realtime` | `WebSocketHandler` + helpers SSE enregistrés |
| `octo-php/symfony-otel` | Span processor + metrics exporter configurés |

Si un package n'est pas installé, aucune erreur n'est levée.

## Recipe Flex

La recipe crée les fichiers suivants :

### `bin/async-server.php`

Script de bootstrap qui boot le kernel Symfony et démarre le serveur OpenSwoole :

```bash
php bin/async-server.php
```

### `.env`

```dotenv
OCTOP_SYMFONY_MEMORY_WARNING_THRESHOLD=104857600
OCTOP_SYMFONY_RESET_WARNING_MS=50
OCTOP_SYMFONY_KERNEL_REBOOT_EVERY=0
```

## Profiler en mode long-running

Le Profiler Symfony fonctionne en mode dev, avec une limitation importante :

Le stockage in-memory du profiler n'est **pas supporté** en long-running (les données seraient accumulées entre requêtes et provoqueraient des leaks mémoire). Le stockage du profiler **doit** utiliser le filesystem ou SQLite :

```yaml
# config/packages/dev/web_profiler.yaml
framework:
    profiler:
        dsn: 'file:%kernel.cache_dir%/profiler'
        # ou : dsn: 'sqlite:%kernel.cache_dir%/profiler.db'
```

Les données profiler des requêtes précédentes sont accessibles via `/_profiler`.

## Optionalité du bundle

Le bundle est optionnel. Le core bridge (`octo-php/symfony-bridge`) reste utilisable sans le bundle en mode callable handler pur. Voir le [README du core bridge](../symfony-bridge/) pour le bootstrap manuel.

## Licence

MIT
