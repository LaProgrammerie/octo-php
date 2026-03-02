# Bench — Async PHP Platform

Benchmark reproductible comparant un pipeline de jobs PHP **synchrone** vs **asynchrone** (OpenSwoole coroutines worker-pool).

## Architecture du bench

Le runner async utilise un **worker-pool** de N coroutines persistantes qui consomment des jobs depuis une `Channel` bornée. Pas de `Coroutine::create()` par job — on bench le throughput du runtime, pas le coût de création de coroutines.

## Lancer un bench

```bash
# Quick (dev, ~30s)
docker compose run --rm app php bench/run.php --mode=both --jobs=500 --concurrency=50 --cpu=5000 --io-ms=2 --json-kb=4

# Standard (défaut)
docker compose run --rm app php bench/run.php

# Stress
docker compose run --rm app php bench/run.php --jobs=10000 --concurrency=500 --cpu=30000 --io-ms=10 --json-kb=16

# Matrix (sweep concurrency × io × cpu)
docker compose run --rm app php bench/run.php --mode=matrix --jobs=500

# Via composer
docker compose run --rm app composer bench
docker compose run --rm app composer bench:matrix
```

## Options CLI

| Option | Défaut | Description |
|--------|--------|-------------|
| `--mode` | `both` | `sync`, `async`, `both`, ou `matrix` |
| `--jobs` | `2000` (matrix: `500`) | Nombre de jobs par run |
| `--concurrency` | `200` | Workers coroutines max (async) |
| `--cpu` | `20000` | Itérations SHA-256 par job |
| `--io-ms` | `5` | Sleep simulé en ms |
| `--json-kb` | `8` | Taille payload JSON en KB |
| `--yield-every` | `0` | Yield coopératif toutes les N itérations CPU (0 = désactivé, async uniquement) |

Variables d'environnement : `BENCH_MODE`, `JOBS`, `CONCURRENCY`, `CPU_N`, `IO_MS`, `JSON_KB`, `YIELD_EVERY`.

## Pipeline de chaque job

2 étapes mesurées séparément en sync et async :

1. **CPU** — `sha256` itératif (N rounds) + JSON encode/decode
2. **IO** — `usleep` (sync) ou `Coroutine::usleep` (async)

Le job retourne `{digest, cpu_ns, io_ns}` pour permettre au bench de distinguer le temps CPU réel du temps d'attente IO.

## Métriques (5 séries)

| Série | Description |
|-------|-------------|
| **exec** | Service time total du job (chrono serré autour de l'exécution) |
| **cpu** | Temps CPU uniquement (hashing + JSON, avant tout sleep) |
| **io_wait** | Temps IO wait (sleep + délai de reprise par l'event loop) |
| **queue_wait** | Temps dans la Channel avant qu'un worker prenne le job (backpressure) |
| **e2e** | Latence totale enqueue → done (queue_wait + exec) |

En sync : queue_wait = 0, e2e = exec.

Le rapport affiche pour chaque série : p50 / p95 / p99 / avg / min / max / sum.

### Health & Saturation

Le rapport inclut un verdict automatique par run basé sur deux métriques d'utilisation :

- **CPU Util** = `cpu_sum / total` — pression CPU réelle (mono-process, parallelism=1)
- **Worker Util** = `exec_sum / (total × concurrency)` — taux d'occupation des workers (inclut IO wait)
- **qw_p95 / exec_p95** — ratio queue wait vs service time (informatif en burst, signal en mode régulé)

**Verdict** (async uniquement) :

- `BASELINE` — sync, pas de verdict
- `OK` — nominal
- `CPU_BOUND` — cpu_util > 85%, le CPU est le bottleneck
- `N/A_SMALL_SAMPLE` — < 200 jobs, statistiques non fiables
- `N/A_MICRO_BENCH` — exec_p50 < 0.2ms, overhead domine la mesure

Note : le verdict `BACKPRESSURE` n'est pas émis en mode burst (arrivée instantanée). Il sera activé avec le mode d'arrivée régulé (V2).

### Note sur le mode d'arrivée

Le bench actuel utilise un mode **burst** : les N jobs sont enfilés d'un coup dans la Channel. Le `queue_wait` reflète donc un cas "file pleine" plus qu'un flux HTTP steady-state. C'est attendu et utile pour mesurer le throughput max, mais ne simule pas un trafic réaliste.

> V2 prévue : `--arrival=poisson --rps=XXX` pour un mode d'arrivée réaliste où `queue_wait` devient une vraie métrique de saturation sous charge.

### Cooperative Yield (--yield-every)

Quand `--yield-every=N` est activé (N > 0), le job insère un `Coroutine::usleep(0)` toutes les N itérations SHA-256. Cela donne au scheduler une chance de reprendre les coroutines en attente IO, réduisant le "convoy effect" sous charge CPU.

Impact mesuré (cpu=20000, concurrency=50, io-ms=5) :

| Metric | No yield | yield-every=5000 | yield-every=2000 |
|--------|----------|-------------------|-------------------|
| IO Wait p50 | 1552ms | 392ms | 163ms |
| CPU p50 | 31ms | 31ms | 31ms |
| Throughput | 31.4 j/s | 31.4 j/s | 31.4 j/s |

Le yield améliore la fairness IO (9.5x) sans coût sur le throughput ni sur le CPU par job. Le temps de yield est exclu de `cpu_ns` pour garder la métrique précise.

Voir [ADR-002](../docs/adr/002-cooperative-yield-cpu-fairness.md) pour le détail.

## Mode Matrix

`--mode=matrix` exécute un sweep systématique :

- **concurrency** : 1, 2, 5, 10, 20, 50, 100
- **io-ms** : 0, 1, 2, 5, 10
- **cpu** : 0, 1000, 5000, 20000

Pour chaque combinaison (cpu, io) : 1 run sync + 7 runs async (un par concurrency).
Total : 20 sync + 140 async = 160 runs.

Output : `bench/results/matrix.csv`

### Interpréter la matrix

Importer le CSV dans un tableur ou utiliser :
```bash
column -t -s, bench/results/matrix.csv | head -30
```

Colonnes clés à tracer :
- `jobs_per_sec` vs `concurrency` (courbe de scaling)
- `exec_p50` vs `concurrency` (le service time doit rester stable)
- `cpu_p50` vs `concurrency` (le temps CPU pur — doit rester constant si pas de contention)
- `qw_p95` vs `concurrency` (queue pressure — monte quand saturé)
- `e2e_p95` vs `concurrency` (latence perçue)
- `cpu_util` vs `concurrency` (pression CPU réelle)
- `worker_util` vs `concurrency` (occupation workers, inclut IO wait)

Scénarios attendus :

- **CPU-bound** (io=0) : async ne gagne rien, throughput plat
- **IO-bound** (cpu=0, io>0) : async scale linéairement jusqu'à saturation
- **Mix** : gain proportionnel à la part IO

Le point de saturation (où `qw_p95` explose) indique la concurrency optimale.

## Fichiers de résultats

| Fichier | Contenu |
|---------|---------|
| `bench/results/latest.md` | Rapport Markdown (modes sync/async/both) |
| `bench/results/latest.csv` | Données brutes CSV |
| `bench/results/matrix.csv` | Sweep matrix agrégé |

Tous sont dans `.gitignore`.
