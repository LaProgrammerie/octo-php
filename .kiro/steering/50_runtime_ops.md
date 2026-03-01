---
title: Runtime & Ops (prod-ready)
inclusion: always
---

# Ops

Toujours livrer :
- Docker image officielle (dev + prod)
- Proxy recommandé devant (TLS, compression, headers, static)
- Healthchecks : /healthz, /readyz
- Graceful shutdown : drain connections, stop accepting, wait inflight, timeout hard
- Reload policy : max requests / max memory / max uptime (configurable)
- Observabilité : OTEL exporter + métriques runtime + logs JSON corrélés

Commandes attendues :
- bin/console async:serve (dev)
- bin/console async:run (prod)
- bin/console async:doctor
- bin/console async:bench