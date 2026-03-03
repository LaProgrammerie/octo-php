# ADR-002: Stratégie de reset entre requêtes

**Status:** Accepted
**Date:** 2025-01-15
**Context:** Symfony Bridge Suite — gestion de l'état entre requêtes dans un processus long-running

## Contexte

Dans un processus long-running OpenSwoole, les services Symfony persistent entre les requêtes. Sans reset, l'état fuit : EntityManager avec des entités détachées, caches accumulés, transactions orphelines, compteurs non réinitialisés.

Le bridge doit garantir un reset fiable après chaque requête, compatible avec l'écosystème Symfony existant.

## Décision

Stratégie de reset par priorité avec hooks extensibles :

1. **Priorité 1** : `$kernel->reset()` si le Kernel implémente `Symfony\Contracts\Service\ResetInterface`
2. **Priorité 2** : `$container->get('services_resetter')->reset()` si le service existe dans le container
3. **Priorité 3** : reset best-effort + log warning

Après le reset Symfony principal, les hooks `ResetHookInterface` custom sont exécutés.

## Justification

### Priorité 1 : ResetInterface

Depuis Symfony 6.4, le Kernel implémente `ResetInterface`. C'est la méthode officielle et la plus complète : elle réinitialise tous les services taggés `kernel.reset`. Couvre 100% des applications Symfony modernes.

### Priorité 2 : services_resetter

Fallback pour les kernels plus anciens ou custom qui n'implémentent pas `ResetInterface`. Le `services_resetter` est le service interne de Symfony qui effectue le reset des services taggés.

### Priorité 3 : best-effort

Si aucune des deux stratégies n'est disponible (kernel custom minimal), le bridge log un warning et continue. Le développeur est informé qu'il doit gérer le reset manuellement.

### Hooks post-reset

L'interface `ResetHookInterface` permet d'enregistrer des hooks custom exécutés après le reset principal. Cas d'usage :

- `DoctrineResetHook` : `$em->clear()` + rollback des transactions orphelines
- Hooks applicatifs : nettoyage de caches custom, reset de connexions externes

Chaque hook est exécuté dans un `try/catch` : un hook qui échoue ne bloque pas les suivants.

## Invariants

- Le reset est **toujours** exécuté dans un bloc `finally` (même si le handler a levé une exception)
- La durée du reset est mesurée et exposée comme métrique (`symfony_reset_duration_ms`)
- Un warning est émis si la durée dépasse le seuil configurable (`OCTOP_SYMFONY_RESET_WARNING_MS`)
- Le reset ne tue jamais le worker : les erreurs sont loguées et le traitement continue

## Alternatives rejetées

### Reset unique via services_resetter uniquement

- Ne couvre pas les kernels modernes qui implémentent `ResetInterface` avec une logique de reset plus complète
- `ResetInterface` est la direction officielle de Symfony

### Reset via shutdown/boot du kernel à chaque requête

- Trop coûteux en performance (reconstruction complète du container)
- Réservé comme fallback configurable via `OCTOP_SYMFONY_KERNEL_REBOOT_EVERY`

### Pas de hooks custom (reset Symfony uniquement)

- Ne couvre pas les cas legacy (Doctrine sans ResetInterface, connexions externes)
- L'extensibilité via `ResetHookInterface` est peu coûteuse et très utile

## Conséquences

- Le bridge ne dépend pas de Doctrine : le `DoctrineResetHook` est fourni comme suggestion optionnelle
- Le bundle auto-tague les services `ResetHookInterface` pour un enregistrement automatique
- Le kernel reboot (`shutdown()` + `boot()`) reste disponible comme fallback robuste pour les leaks que le reset standard ne couvre pas
- L'ordering `terminate → reset` est garanti, compatible avec le Profiler Symfony
