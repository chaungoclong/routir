<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Runner;

use Routir\Benchmarks\Model\BenchmarkMetrics;

/**
 * Measures percentile latencies and throughput using high-resolution monotonic clocks.
 */
final class LatencySampler
{
    /**
     * Execute warmup runs and measure latency distribution over the configured iterations.
     *
     * @param callable(): mixed $operation Operation under test.
     * @param int $iterations Number of timed iterations.
     * @param int $warmup Number of untimed warmup iterations.
     *
     * @return BenchmarkMetrics
     */
    public function sample(callable $operation, int $iterations = 2000, int $warmup = 100): BenchmarkMetrics
    {
        for ($i = 0; $i < $warmup; $i++) {
            $operation();
        }

        $samples = [];
        $totalStart = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $t0 = hrtime(true);
            $operation();
            $samples[] = (hrtime(true) - $t0) / 1000.0;
        }
        $totalSec = (hrtime(true) - $totalStart) / 1e9;
        sort($samples);

        return new BenchmarkMetrics(
            p50Us: $samples[(int) ($iterations * 0.50)],
            p95Us: $samples[(int) ($iterations * 0.95)],
            p99Us: $samples[(int) ($iterations * 0.99)],
            opsPerSec: $iterations / max(1e-9, $totalSec),
        );
    }
}
