---
title: Conventions repo
inclusion: always
---

# Conventions

Structure :
- packages/ (libs réutilisables)
- platform/ (runtime pack + intégrations)
- skeleton/ (create-project)
- docs/ (decision records, design)

Docs obligatoires :
- ADR (Architecture Decision Records)
- README avec : install, run, bench, deploy, policies, limites

Qualité :
- CI : lint + static + tests + bench smoke
- Pas de dépendance sans justification