# octo-php/platform

Meta-package installant la stack complète OctoPHP pour la plateforme async PHP.

## Installation

```bash
composer require octo-php/platform
```

Ou via le skeleton (recommandé pour un nouveau projet) :

```bash
composer create-project octo-php/skeleton my-app
```

## Packages inclus

| Package | Description |
|---|---|
| [runtime-pack](../packages/runtime-pack/) | Runtime OpenSwoole — HTTP server, healthchecks, graceful shutdown, reload policy, structured concurrency |
| [symfony-bridge](../packages/symfony-bridge/) | Core — conversion HTTP, lifecycle long-running, reset, streaming, métriques |
| [symfony-bundle](../packages/symfony-bundle/) | Bundle Symfony, auto-configuration, recipe Flex |
| [symfony-messenger](../packages/symfony-messenger/) | Transport Messenger in-process via channels OpenSwoole |
| [symfony-realtime](../packages/symfony-realtime/) | WebSocket handler, helpers SSE avancés |
| [symfony-otel](../packages/symfony-otel/) | Export OpenTelemetry (traces + métriques) |

## Installation individuelle

Si vous n'avez pas besoin de toute la stack, installez les packages séparément :

```bash
# Runtime seul (sans Symfony)
composer require octo-php/runtime-pack

# Core bridge uniquement
composer require octo-php/symfony-bridge

# Ajout progressif
composer require octo-php/symfony-bundle
composer require octo-php/symfony-messenger
composer require octo-php/symfony-realtime
composer require octo-php/symfony-otel
```

## Licence

MIT
