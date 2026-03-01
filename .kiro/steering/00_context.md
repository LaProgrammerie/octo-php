---
title: Contexte projet
inclusion: always
---

# Contexte

Objectif : construire une **plateforme async PHP** opinionated (à la API Platform), avec un runtime OpenSwoole comme golden path.

Ce n’est pas “un framework async de plus”.
C’est : runtime pack + skeleton + conventions + tooling + garanties (timeouts/limits/cancel/backpressure/OTEL).

Principes d’architecture :
- API-first
- Logique métier isolée des frameworks
- Infra as Code systématique
- Observabilité dès V1
- Éviter sur-ingénierie et buzzwords

Quand tu proposes une solution :
- Privilégie la simplicité robuste
- Mesure (bench) avant d’optimiser
- Anticipe la prod (healthchecks, reload policy, memory cap, p95/p99)