---
title: Plateforme async — OpenSwoole golden path
inclusion: always
---

# Golden path

Runtime officiel : **OpenSwoole**.

Conséquences assumées :
- long-running process => risques de state leaks (statics/singletons/caches)
- compat legacy (PDO/Doctrine/libs sync) => stratégie “blocking isolation” bornée obligatoire
- promesse : fan-out massif, streaming (SSE/WS), temps réel, latences p95/p99 maîtrisées

Ne pas dériver vers FrankenPHP : non-goal.