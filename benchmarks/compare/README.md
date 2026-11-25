# Routir Cross-Router Benchmark

This benchmark compares observable routing work under controlled, repeatable conditions. Build, cache warmup, and route registration happen before timing. Direct matchers and PSR-7 dispatchers are reported in separate tables because they perform different amounts of work.

## Measurement model

- Each adapter and route-count pair runs in a fresh PHP process. This isolates JIT traces, OPcache state, static state, garbage collection, and framework bootstrapping from other adapters.
- Every scenario is verified before measurement: static and dynamic targets must match, while the miss target must not match.
- Warmup continues until both the minimum call count and minimum wall-clock duration are satisfied. The defaults exceed PHP's standard tracing-JIT hot-function threshold.
- Latency and throughput are measured separately:
  - Latency records individually timed calls and reports nearest-rank p50, p95, and p99 values.
  - Throughput runs seven approximately 500 ms batches, reports the median rate, and includes relative median absolute deviation (`±rMAD`).
- Sample counts are adaptive. Fast operations receive up to 10,000 latency samples; slow operations use a bounded time budget with at least 100 samples.
- Setup, compilation, cache generation, correctness verification, and worker startup are excluded from the measured intervals.

The timer and adapter-call overhead remain part of each latency sample. This overhead is identical in shape across adapters within the same category; do not compare results across different machines or PHP configurations.

## Runtime modes

Use the dedicated targets to avoid conflating OPcache optimization with JIT behavior:

```bash
make bench-opcache     # OPcache optimizer ON, JIT OFF
make bench-jit         # OPcache optimizer ON, tracing JIT ON
make bench-no-opcache  # OPcache and JIT OFF
make bench             # Run all three modes
```

For a short integration smoke test that is not suitable for published numbers:

```bash
make bench-quick
```

Pass benchmark options through `BENCH_ARGS`:

```bash
make bench-opcache BENCH_ARGS="--routes=100,500 --only=routir-compiled-o2,symfony-compiled"
```

Available options:

| Option | Default | Meaning |
| --- | ---: | --- |
| `--routes` | `100,500` | Comma-separated route counts |
| `--only` | all | Comma-separated adapter IDs |
| `--iterations` | `10000` | Maximum latency samples |
| `--latency-min-samples` | `100` | Minimum latency samples |
| `--latency-ms` | `250` | Latency sampling time budget |
| `--warmup` | `128` | Minimum warmup calls |
| `--warmup-ms` | `50` | Minimum warmup duration |
| `--throughput-ms` | `500` | Target duration of each throughput batch |
| `--repeats` | `7` | Throughput batches; minimum three |

## Producing publishable results

1. Use a quiet, plugged-in machine with a fixed performance power profile.
2. Avoid concurrent builds, containers, indexing, backups, and browser workloads.
3. Pin the Docker/PHP process to an isolated physical core when the host permits it.
4. Run each runtime mode separately and repeat the complete command at least three times.
5. Prefer results with `rMAD <= 2%`; investigate or rerun scenarios above `5%`.
6. Compare only rows in the same category, route count, PHP build, OPcache/JIT mode, and machine.
7. Report PHP version, CPU, OS, container limits, command line, route dataset, p50/p95/p99, median throughput, and rMAD.

The default dataset is deterministic and balanced equally across static routes, single integer parameters, and multi-parameter routes. The dynamic target selects the last multi-parameter route to exercise worst-case registration order without changing semantic work between adapters.
