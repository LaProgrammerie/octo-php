# octo-php/symfony-realtime

Package temps réel pour la plateforme async PHP — WebSocket handler, helpers SSE avancés (formatage W3C, keep-alive, reconnection), et routage HTTP/WS.

## Installation

```bash
composer require octo-php/symfony-realtime
```

## WebSocket

### Configuration OpenSwoole

Le support WebSocket nécessite l'activation du mode WebSocket server dans OpenSwoole. Le `RealtimeServerAdapter` remplace le handler HTTP standard pour router les requêtes :

```php
use Octo\RuntimePack\ServerBootstrap;
use Octo\SymfonyRealtime\RealtimeServerAdapter;

$adapter = new RealtimeServerAdapter(
    httpHandler: $httpKernelAdapter,
    wsHandler: $myWebSocketHandler,
);

ServerBootstrap::run(
    appHandler: $adapter,
    production: true,
);
```

### WebSocketHandler

Implémentez l'interface `WebSocketHandler` pour gérer les connexions :

```php
use Octo\SymfonyRealtime\WebSocketHandler;
use Octo\SymfonyRealtime\WebSocketContext;

final class ChatHandler implements WebSocketHandler
{
    public function onOpen(WebSocketContext $context): void
    {
        // Connexion ouverte
    }

    public function onMessage(WebSocketContext $context, string $data): void
    {
        // Frame reçue — répondre directement
        $context->send('Echo: ' . $data);
    }

    public function onClose(WebSocketContext $context): void
    {
        // Connexion fermée
    }
}
```

### WebSocketContext

DTO readonly contenant les informations de connexion :

- `connectionId` — identifiant unique de la connexion
- `requestId` — request_id propagé depuis la requête d'upgrade
- `headers` — headers de la requête d'upgrade HTTP
- `send(string $data): void` — envoyer une frame au client
- `close(): void` — fermer la connexion

### Routage HTTP / WebSocket

Le `RealtimeServerAdapter` détecte automatiquement les requêtes d'upgrade WebSocket via les headers `Upgrade: websocket` + `Connection: Upgrade` (case-insensitive) :

- Requêtes WebSocket → déléguées au `WebSocketHandler` (frames brutes, pas de conversion HttpFoundation)
- Requêtes HTTP → déléguées au `HttpKernelAdapter` du core bridge

### Max lifetime

Chaque connexion WebSocket a un max lifetime configurable :

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `OCTOP_SYMFONY_WS_MAX_LIFETIME_SECONDS` | int (s) | `3600` | Durée maximale d'une connexion WebSocket |

Via le bundle :

```yaml
octo:
    realtime:
        ws_max_lifetime_seconds: 3600
```

## SSE avancé vs SSE basique

### SSE basique (core bridge)

Le core bridge (`octo-php/symfony-bridge`) fournit le **mécanisme de streaming** : `StreamedResponse` avec `Content-Type: text/event-stream` est automatiquement streamé via `ResponseFacade::write()` avec compression et buffering désactivés.

C'est suffisant pour des cas simples où vous formatez les événements SSE manuellement.

### SSE avancé (ce package)

Ce package fournit le **protocole SSE structuré** :

#### SseEvent

Formatage conforme à la spécification W3C :

```php
use Octo\SymfonyRealtime\SseEvent;

$event = new SseEvent(
    data: "Hello world",
    event: 'message',
    id: '42',
    retry: 3000,
);

echo $event->format();
// event: message
// data: Hello world
// id: 42
// retry: 3000
//
```

Les données multi-lignes sont automatiquement découpées en lignes `data:` séparées.

`SseEvent::parse()` permet le round-trip : `parse(format($event))` restitue les champs originaux.

#### SseStream

Envoi d'événements SSE via `ResponseFacade::write()` avec :

- **Keep-alive périodique** : commentaire SSE `: keep-alive\n\n` envoyé toutes les 15 secondes (configurable) pour maintenir la connexion
- **Reconnection** : support du header `Last-Event-ID` pour la reconnexion client

## Métriques

| Métrique | Type | Description |
|---|---|---|
| `ws_connections_active` | gauge | Connexions WebSocket actives |
| `ws_messages_received_total` | counter | Messages WebSocket reçus |
| `ws_messages_sent_total` | counter | Messages WebSocket envoyés |

## Licence

MIT
