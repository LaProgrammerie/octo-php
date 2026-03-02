# ADR-001: HttpFoundation directe vs PSR-7

**Status:** Accepted
**Date:** 2025-01-15
**Context:** Symfony Bridge Suite — choix de la couche HTTP interne

## Contexte

Le bridge doit convertir les requêtes/réponses entre OpenSwoole et Symfony. Deux approches sont possibles :

1. **HttpFoundation directe** : convertir OpenSwoole Request → `Symfony\Component\HttpFoundation\Request` directement.
2. **PSR-7 intermédiaire** : convertir OpenSwoole Request → PSR-7 (`nyholm/psr7`) → HttpFoundation (via `symfony/psr-http-message-bridge`).

## Décision

Utiliser HttpFoundation directement, sans couche PSR-7 intermédiaire.

## Justification

- **Zéro overhead de conversion** : une seule conversion (OpenSwoole → HttpFoundation) au lieu de deux (OpenSwoole → PSR-7 → HttpFoundation). Sur un serveur long-running traitant des milliers de requêtes/seconde, chaque allocation compte.
- **Symfony utilise nativement HttpFoundation** : le HttpKernel, les controllers, les listeners, le Profiler — tout l'écosystème Symfony travaille avec HttpFoundation. PSR-7 serait un détour inutile.
- **Pas de dépendance supplémentaire** : évite `nyholm/psr7` et `symfony/psr-http-message-bridge`, réduisant l'arbre de dépendances et la surface de maintenance.
- **Mapping complet** : HttpFoundation expose des API riches (cookies typés, fichiers uploadés, attributs de requête) que PSR-7 ne couvre pas nativement. Le mapping direct préserve toutes les fonctionnalités sans perte.

## Alternatives rejetées

### PSR-7 comme couche intermédiaire

- Ajouterait 2 dépendances Composer
- Double conversion (OpenSwoole → PSR-7 → HttpFoundation) avec allocations supplémentaires
- Les objets PSR-7 sont immutables, ce qui complique le mapping des attributs mutables de HttpFoundation
- Aucun bénéfice concret : le bridge cible exclusivement Symfony, pas un écosystème PSR-7 générique

### PSR-7 comme API publique du bridge

- Forcerait les utilisateurs Symfony à travailler avec PSR-7 au lieu de HttpFoundation
- Incompatible avec l'écosystème Symfony (Profiler, Security, Form, etc.)
- Non-goal : le bridge n'est pas un adaptateur HTTP générique

## Conséquences

- Le bridge est couplé à HttpFoundation — c'est un choix assumé pour un bridge Symfony
- Les bibliothèques PSR-7 pures ne sont pas directement compatibles (elles doivent passer par le HttpKernel Symfony)
- La conversion est simple, directe, et testable via property-based testing (round-trip)
- Si un besoin PSR-7 émerge (ex: middleware PSR-15), un package séparé pourra être créé sans impacter le core
