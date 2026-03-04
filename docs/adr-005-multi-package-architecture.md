# ADR-005: Architecture multi-packages pour la Symfony Bridge Suite

**Status:** Accepted
**Date:** 2025-01-15
**Context:** Symfony Bridge Suite — organisation en packages Composer indépendants

## Contexte

La Symfony Bridge Suite fournit l'intégration entre le runtime OpenSwoole et Symfony. Les fonctionnalités couvrent : conversion HTTP, lifecycle long-running, Messenger, WebSocket, SSE, OpenTelemetry, et auto-configuration via bundle.

Un package monolithique forcerait l'installation de toutes les dépendances (Messenger, OTEL SDK, etc.) même pour les projets qui n'en ont pas besoin.

## Décision

Organiser la suite en 6 packages Composer indépendants dans le monorepo :

| Package | Responsabilité |
|---|---|
| `octo-php/symfony-bridge` | Core : conversion HTTP, lifecycle, reset, streaming, métriques |
| `octo-php/symfony-bundle` | Bundle Symfony, auto-configuration, recipe Flex |
| `octo-php/symfony-messenger` | Transport Messenger in-process |
| `octo-php/symfony-realtime` | WebSocket + helpers SSE avancés |
| `octo-php/symfony-otel` | Export OpenTelemetry (traces + métriques) |
| `octo-php/platform` | Meta-package installant toute la stack (runtime-pack + bridges) |

### Principes

- Le core bridge fonctionne seul, sans bundle, sans Messenger, sans OTEL, sans WebSocket
- Le bundle est optionnel : le bridge reste un callable handler pur
- Chaque package d'extension dépend du core bridge mais pas du bundle
- Le bundle auto-détecte les packages d'extension installés via `class_exists()`
- Le runtime pack reste indépendant de Symfony

### Versioning

SemVer coordonné par release : tous les packages partagent la même version (major.minor.patch) lors de chaque release. Le meta-package `platform` centralise les contraintes de versions compatibles.

## Justification

- **Installation minimale** : seul le core est requis (~3 dépendances Symfony). Pas de `symfony/messenger`, `open-telemetry/sdk`, etc. si non utilisés.
- **Arbre de dépendances réduit** : un projet API simple n'a pas besoin de 15 dépendances transitives pour du WebSocket ou de l'OTEL.
- **Testabilité isolée** : chaque package a sa propre suite de tests. Les tests du core ne dépendent pas de Messenger ou d'OTEL.
- **Évolutivité** : ajout de nouveaux packages (ex: `symfony-cache`, `symfony-security`) sans impacter le core.
- **Responsabilité claire** : chaque package a un périmètre défini et documenté.

## Alternatives rejetées

### Package monolithique unique

- Force l'installation de toutes les dépendances
- Arbre de dépendances lourd (OTEL SDK, Messenger, etc.)
- Difficile à tester isolément
- Un bug dans le WebSocket impacte les releases du core

### Packages sans meta-package

- Oblige les utilisateurs qui veulent tout à gérer 5 lignes `composer require`
- Pas de garantie de combinaison de versions testée
- Le meta-package `platform` résout ce problème

### Packages avec versioning indépendant

- Complexifie la matrice de compatibilité (quelle version de messenger est compatible avec quelle version du core ?)
- Le versioning coordonné par release simplifie la gestion pour les utilisateurs et la CI

## Conséquences

- 6 `composer.json` à maintenir dans le monorepo
- Un outil de split (`splitsh-lite`) est nécessaire pour publier chaque package vers son repo Composer individuel
- La CI exécute les tests de tous les packages à chaque PR
- Chaque package a sa propre matrice de tests (PHP versions, Symfony versions)
- Le tag de release est coordonné : un script tag tous les packages avec la même version
- La migration depuis un éventuel monolithe est transparente via `platform`
