# octo-php/symfony-otel

Intégration OpenTelemetry pour la plateforme async PHP — export de traces et métriques coroutine-safe depuis le bridge Symfony.

## Installation

```bash
composer require octo-php/symfony-otel
```

Ce package dépend de `open-telemetry/sdk` et `open-telemetry/exporter-otlp`. Si vous ne souhaitez pas d'OTEL, n'installez pas ce package.

## Configuration

La configuration utilise exclusivement les variables d'environnement standard OpenTelemetry :

| Variable | Type | Description |
|---|---|---|
| `OTEL_EXPORTER_OTLP_ENDPOINT` | string | Endpoint de l'exporter OTLP (ex: `http://localhost:4318`) |

Aucune variable d'environnement custom n'est introduite pour la configuration OTEL.

### Via le bundle

```yaml
# config/packages/octo.yaml
octo:
    otel:
        enabled: true
```

L'activation/désactivation se fait via le bundle. L'endpoint est toujours configuré via `OTEL_EXPORTER_OTLP_ENDPOINT`.

## Traces

### Root span

Un span `SERVER` est créé pour chaque requête Symfony, couvrant l'intégralité du cycle de vie dans le bridge (du début du handle jusqu'après le reset).

Attributs du root span :

| Attribut | Description |
|---|---|
| `http.method` | Méthode HTTP (GET, POST, etc.) |
| `http.url` | URL de la requête |
| `http.status_code` | Code de statut de la réponse |
| `http.request_id` | Request ID propagé |
| `symfony.route` | Nom de la route Symfony |
| `symfony.controller` | Nom du controller Symfony |

### Child spans

Trois child spans `INTERNAL` sont créés pour chaque requête :

| Span | Description |
|---|---|
| `symfony.kernel.handle` | Durée du `HttpKernel::handle()` |
| `symfony.response.convert` | Durée de la conversion de la réponse |
| `symfony.reset` | Durée du reset/terminate |

### Propagation du trace context

Le package propage le trace context entrant via les headers W3C Trace Context (`traceparent`, `tracestate`) depuis la requête OpenSwoole vers le span OTEL. Les requêtes entrantes avec un trace context existant sont rattachées au trace parent.

### Gestion des exceptions

Si une exception se produit avant la création des child spans, le root span capture l'exception et se termine correctement (pas de span partiel ou orphelin).

## Métriques OTEL

Les métriques du `MetricsBridge` sont exportées vers OTEL :

| Métrique | Type | Description |
|---|---|---|
| `symfony_requests_total` | counter | Requêtes traitées |
| `symfony_request_duration_ms` | histogram | Durée de traitement |
| `symfony_exceptions_total` | counter | Exceptions levées |
| `symfony_reset_duration_ms` | histogram | Durée du reset |

## Batch processor coroutine-safe

Le `CoroutineSafeBatchProcessor` exporte les spans de manière non-bloquante :

- Batch avec flush quand le batch est plein
- Timer OpenSwoole pour export périodique
- Export via coroutine OpenSwoole (ne bloque pas l'event loop)

## Licence

MIT
