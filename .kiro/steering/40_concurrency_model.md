---
title: Modèle de concurrence (Go-like) — invariants
inclusion: always
---

# Concurrency model (à imposer)

Primitives publiques minimales :
- Context : deadline + cancellation + valeurs de contexte (trace, tenant, request id)
- TaskGroup : structured concurrency (spawn/join/supervision)
- timeout() et limit() obligatoires
- Channel<T> bounded pour backpressure (V2 si besoin)

Invariants :
- interdiction de spawn “global” : toute tâche a un parent (TaskGroup)
- aucune tâche sans deadline
- cancellation propagée selon policy (fail-fast par défaut)
- limites de concurrence par scope (route/tenant/pool)
- compat bloquant via BlockingPool borné (queue finie, timeouts, métriques)

Risque principal à surveiller :
- starvation / surcharge loop / runaway tasks
=> instrumentation et garde-fous indispensables.