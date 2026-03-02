# async-platform/symfony-bridge-full

Meta-package installant la suite complète Symfony Bridge pour la plateforme async PHP.

## Installation

```bash
composer require async-platform/symfony-bridge-full
```

Cette commande installe les 5 packages de la suite :

| Package | Description |
|---|---|
| [symfony-bridge](../symfony-bridge/) | Core — conversion HTTP, lifecycle long-running, reset, streaming, métriques |
| [symfony-bundle](../symfony-bundle/) | Bundle Symfony, auto-configuration, recipe Flex |
| [symfony-messenger](../symfony-messenger/) | Transport Messenger in-process via channels OpenSwoole |
| [symfony-realtime](../symfony-realtime/) | WebSocket handler, helpers SSE avancés |
| [symfony-otel](../symfony-otel/) | Export OpenTelemetry (traces + métriques) |

## Installation individuelle

Si vous n'avez pas besoin de tous les packages, installez-les séparément pour un arbre de dépendances réduit :

```bash
# Core uniquement (suffisant pour la plupart des cas)
composer require async-platform/symfony-bridge

# Ajout progressif selon les besoins
composer require async-platform/symfony-bundle
composer require async-platform/symfony-messenger
composer require async-platform/symfony-realtime
composer require async-platform/symfony-otel
```

Seul le core bridge est requis. Les autres packages sont opt-in.

## Migration depuis le monolithe

Si vous utilisiez l'ancien package monolithique, `symfony-bridge-full` installe tous les packages avec un comportement identique. La migration est transparente pour le code applicatif existant.

## Licence

MIT
