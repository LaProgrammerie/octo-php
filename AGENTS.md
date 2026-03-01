# AGENTS — Async PHP Platform (OpenSwoole)

Ce repo construit une plateforme async PHP opinionated, basée sur OpenSwoole.

## Priorités non négociables
- Robustesse / maintenabilité / scalabilité / performance mesurable
- Observabilité first-class (traces + métriques + logs corrélés)
- Structured concurrency imposée (pas seulement “possible”)
- Timeouts, cancellation, limits et backpressure par défaut
- Compat legacy via “blocking isolation” bornée (pool) — pas de queue infinie

## Règles pour l’agent
- Toujours proposer une solution industrialisable et testable.
- Toujours expliciter : hypothèses + non-goals + trade-offs + risques.
- Ne pas inventer d’API : si un format/feature dépend d’une spec, vérifier la doc.
- Fournir des fichiers complets et une arborescence quand demandé.