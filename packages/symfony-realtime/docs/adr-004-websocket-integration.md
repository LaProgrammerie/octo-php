# ADR-004: Intégration WebSocket via RealtimeServerAdapter

**Status:** Accepted
**Date:** 2025-01-15
**Context:** Symfony Bridge Suite — support WebSocket sur le runtime OpenSwoole

## Contexte

OpenSwoole supporte nativement les WebSocket au niveau du serveur HTTP. Le bridge doit permettre de servir à la fois des requêtes HTTP (via Symfony HttpKernel) et des connexions WebSocket depuis le même serveur.

## Décision

Fournir un `RealtimeServerAdapter` comme callable routeur compatible avec `ServerBootstrap::run()`, qui :

1. Détecte les requêtes d'upgrade WebSocket via les headers `Upgrade: websocket` + `Connection: Upgrade` (case-insensitive)
2. Route les requêtes WebSocket vers un `WebSocketHandler` enregistré (frames brutes, pas de conversion HttpFoundation)
3. Route les requêtes HTTP vers le `HttpKernelAdapter` du core bridge

## Justification

### Callable routeur plutôt que modification du runtime pack

- Le runtime pack reste indépendant de Symfony (invariant architectural)
- `ServerBootstrap::run()` accepte un callable : le `RealtimeServerAdapter` est un callable qui encapsule le routage
- Pas de modification du runtime pack nécessaire

### Frames brutes pour WebSocket

- Les frames WebSocket ne sont pas des requêtes HTTP : la conversion vers HttpFoundation n'a pas de sens
- Le `WebSocketHandler` reçoit les frames directement via un `WebSocketContext` (DTO readonly)
- Le context fournit : connectionId, requestId, headers, `send()`, `close()`

### Max lifetime par connexion

- Chaque connexion WebSocket a un max lifetime configurable (`OCTOP_SYMFONY_WS_MAX_LIFETIME_SECONDS`, défaut : 3600)
- Protège contre les connexions zombies et les leaks mémoire
- Respecte les deadlines et la cancellation du runtime pack

## Alternatives rejetées

### WebSocket via un serveur séparé

- Complexifie le déploiement (deux processus, deux ports)
- Perd la corrélation request_id entre HTTP et WS
- OpenSwoole supporte nativement les deux protocoles sur le même serveur

### Conversion des frames WS en HttpFoundation Request

- Les frames WS sont des messages bidirectionnels, pas des requêtes HTTP
- Forcer le modèle request/response de HttpFoundation sur du WebSocket est un anti-pattern
- Le HttpKernel Symfony n'est pas conçu pour gérer des connexions persistantes

### Intégration directe dans le HttpKernelAdapter

- Violerait la séparation des responsabilités
- Le core bridge ne doit pas dépendre du mode WebSocket
- Le package `symfony-realtime` est opt-in

## Conséquences

- Le `RealtimeServerAdapter` est un package séparé (`symfony-realtime`), opt-in
- Le core bridge n'a aucune connaissance des WebSocket
- Les métriques WebSocket (connexions actives, messages reçus/envoyés) sont exposées via `MetricsCollector`
- Le routage HTTP/WS est transparent : les requêtes HTTP passent par le cycle de vie Symfony complet
- Le mode WebSocket server d'OpenSwoole doit être activé dans la configuration du serveur
