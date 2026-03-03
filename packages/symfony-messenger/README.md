# octo-php/symfony-messenger

Transport Symfony Messenger in-process pour la plateforme async PHP — message passing via channels OpenSwoole bornés avec backpressure.

## Installation

```bash
composer require octo-php/symfony-messenger
```

## Configuration

### DSN

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async:
                dsn: 'openswoole://default'
                options:
                    channel_capacity: 100
                    send_timeout: 5.0
```

### Variables d'environnement

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `OCTOP_SYMFONY_MESSENGER_CHANNEL_CAPACITY` | int | `100` | Capacité du channel borné (backpressure) |
| `OCTOP_SYMFONY_MESSENGER_CONSUMERS` | int | `1` | Nombre de coroutines consommatrices par worker |
| `OCTOP_SYMFONY_MESSENGER_SEND_TIMEOUT` | float (s) | `5.0` | Timeout d'envoi quand le channel est plein |

### Via le bundle

```yaml
# config/packages/octo.yaml
octo:
    messenger:
        channel_capacity: 100
        consumers: 1
        send_timeout: 5.0
```

## Fonctionnement

Le transport utilise un channel OpenSwoole borné pour le message passing in-process :

- `send()` : push le message dans le channel. Si le channel est plein, la coroutine yield (backpressure) jusqu'à ce qu'un espace soit disponible ou que le timeout soit atteint.
- `get()` : pop un message du channel avec un poll timeout de 1s.
- `ack()` : no-op (in-process, pas de broker externe).
- `reject()` : log warning.

Les consumers sont spawnés au boot du worker via structured concurrency (`TaskGroup`) et annulés proprement au shutdown (pas de coroutines zombies).

## Métriques

| Métrique | Type | Description |
|---|---|---|
| `messenger_messages_sent_total` | counter | Messages envoyés |
| `messenger_messages_consumed_total` | counter | Messages consommés |
| `messenger_channel_size` | gauge | Taille courante du channel |

## Limitations

### Transport in-process uniquement

Ce transport est **non distribué** et **non durable** :

- Les messages sont stockés en mémoire dans le channel OpenSwoole du worker
- Le channel est **isolé par worker** : les messages ne sont pas partagés entre workers
- Les messages **non consommés au moment du restart du worker sont perdus**

### Cas d'usage adaptés

- Fire-and-forget (notifications, logs asynchrones)
- Background jobs légers (envoi d'emails, cache warming)
- Traitement asynchrone intra-requête

### Cas d'usage non adaptés

Pour le messaging durable, distribué ou critique, utiliser les transports externes standard Symfony :

- AMQP (RabbitMQ)
- Redis
- Doctrine

## Retry et failure transport

Les mécanismes standard de retry et failure transport de Symfony Messenger s'appliquent normalement :

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: 'openswoole://default'
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2

        failure_transport: failed

        transports:
            failed:
                dsn: 'doctrine://default?queue_name=failed'
```

Pour les messages critiques, configurez un failure transport durable (Doctrine, Redis) afin de ne pas perdre les messages en cas de restart.

## Licence

MIT
