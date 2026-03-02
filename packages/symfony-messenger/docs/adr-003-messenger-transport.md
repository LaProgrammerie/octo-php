# ADR-003: Transport Messenger in-process via channels OpenSwoole

**Status:** Accepted
**Date:** 2025-01-15
**Context:** Symfony Bridge Suite — transport Messenger pour le message passing asynchrone

## Contexte

Symfony Messenger supporte plusieurs transports (AMQP, Redis, Doctrine, in-memory). Pour les cas d'usage simples (fire-and-forget, background jobs légers), un transport in-process natif OpenSwoole évite la dépendance à un broker externe.

## Décision

Implémenter un transport Messenger utilisant les channels OpenSwoole bornés pour le message passing in-process, non-durable.

### Caractéristiques

- Channel borné avec capacité configurable (défaut : 100)
- Backpressure native : `send()` bloque la coroutine si le channel est plein (yield à l'event loop)
- Timeout configurable sur `send()` → `TransportException` si dépassé
- Channel isolé par worker (pas de partage entre workers)
- Consumers spawnés via structured concurrency (`TaskGroup`)
- DSN : `openswoole://default`

## Justification

- **Simplicité** : pas de broker externe à installer, configurer, monitorer
- **Performance** : communication in-process via channel OpenSwoole, zéro sérialisation réseau
- **Backpressure native** : le channel borné empêche l'accumulation non contrôlée de messages
- **Structured concurrency** : les consumers sont des coroutines gérées par `TaskGroup`, avec deadlines et cancellation. Pas de coroutines zombies.
- **Compatibilité Messenger** : implémente `TransportInterface`, compatible avec les middlewares standard (retry, failure transport)

## Limitations assumées

- **Non-durable** : les messages sont en mémoire. Ils sont perdus au restart du worker.
- **Non-distribué** : le channel est isolé par worker. Les messages ne sont pas partagés entre workers.
- **In-process uniquement** : pas de communication inter-processus.

Ces limitations sont documentées et assumées. Pour le messaging durable ou distribué, les transports externes standard Symfony (AMQP, Redis, Doctrine) sont recommandés.

## Alternatives rejetées

### Transport via fichiers / SQLite

- Overhead I/O pour chaque message
- Complexité de gestion des locks en environnement concurrent
- Le cas d'usage cible (fire-and-forget léger) ne justifie pas la durabilité

### Transport via shared memory OpenSwoole (Table)

- API plus complexe (sérialisation manuelle, gestion des index)
- Pas de sémantique FIFO native
- Le channel borné est la primitive idéale pour le message passing

### Pas de transport in-process (uniquement transports externes)

- Force l'installation d'un broker externe même pour des cas simples
- Augmente la complexité d'infrastructure pour les petits projets

## Conséquences

- Le transport est adapté aux cas simples et légers
- Les messages critiques doivent utiliser un failure transport durable (Doctrine, Redis)
- Les mécanismes standard de retry Symfony s'appliquent normalement
- Les métriques (sent, consumed, channel_size) permettent de monitorer la backpressure
- Le consumer lifecycle est propre : start au boot, stop au shutdown, pas de zombies
