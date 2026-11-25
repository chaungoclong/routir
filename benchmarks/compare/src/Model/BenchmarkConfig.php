<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Model;

/**
 * Defines adaptive warmup, latency, and throughput measurement budgets.
 */
final readonly class BenchmarkConfig
{
    /**
     * @param int $latencyMaxSamples Maximum individually timed latency samples.
     * @param int $latencyMinSamples Minimum samples retained for percentile calculation.
     * @param int $latencyBudgetMs Target wall-clock budget for latency sampling.
     * @param int $warmupMinIterations Minimum untimed calls before measurement.
     * @param int $warmupMinDurationMs Minimum warmup wall-clock duration.
     * @param int $throughputDurationMs Target wall-clock duration of each throughput batch.
     * @param int $throughputRepeats Number of independent throughput batches.
     */
    public function __construct(
        public int $latencyMaxSamples = 10_000,
        public int $latencyMinSamples = 100,
        public int $latencyBudgetMs = 250,
        public int $warmupMinIterations = 128,
        public int $warmupMinDurationMs = 50,
        public int $throughputDurationMs = 500,
        public int $throughputRepeats = 7,
    ) {
    }

    /**
     * Serialize configuration as worker-process CLI arguments.
     *
     * @return list<string>
     */
    public function toCliArguments(): array
    {
        return [
            '--iterations=' . $this->latencyMaxSamples,
            '--latency-min-samples=' . $this->latencyMinSamples,
            '--latency-ms=' . $this->latencyBudgetMs,
            '--warmup=' . $this->warmupMinIterations,
            '--warmup-ms=' . $this->warmupMinDurationMs,
            '--throughput-ms=' . $this->throughputDurationMs,
            '--repeats=' . $this->throughputRepeats,
        ];
    }
}
