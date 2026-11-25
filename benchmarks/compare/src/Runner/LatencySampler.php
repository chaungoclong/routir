<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Runner;

use Routir\Benchmarks\Model\BenchmarkConfig;
use Routir\Benchmarks\Model\BenchmarkMetrics;

/**
 * Measures percentile latencies and throughput using high-resolution monotonic clocks.
 */
final class LatencySampler
{
    private const int CALIBRATION_TARGET_NS = 5_000_000;

    private const int MAX_BATCH_ITERATIONS = 100_000_000;

    /**
     * Warm the operation, sample latency within a time budget, and measure repeated throughput batches.
     *
     * @param callable(): mixed $operation Operation under test.
     * @param BenchmarkConfig $config Adaptive measurement configuration.
     *
     * @return BenchmarkMetrics
     */
    public function sample(callable $operation, BenchmarkConfig $config): BenchmarkMetrics
    {
        $warmupStart = hrtime(true);
        $warmupIterations = 0;
        $warmupTargetNs = $config->warmupMinDurationMs * 1_000_000;
        do {
            $operation();
            $warmupIterations++;
        } while (
            $warmupIterations < $config->warmupMinIterations
            || hrtime(true) - $warmupStart < $warmupTargetNs
        );

        $warmupElapsedNs = max(1, hrtime(true) - $warmupStart);
        $roughOperationNs = $warmupElapsedNs / $warmupIterations;
        $calibrationIterations = max(
            1,
            min(10_000, (int) ceil(self::CALIBRATION_TARGET_NS / max(1.0, $roughOperationNs))),
        );
        $calibrationStart = hrtime(true);
        for ($i = 0; $i < $calibrationIterations; $i++) {
            $operation();
        }
        $estimatedOperationNs = max(1, hrtime(true) - $calibrationStart) / $calibrationIterations;
        $latencySamples = max(
            $config->latencyMinSamples,
            min(
                $config->latencyMaxSamples,
                (int) floor(($config->latencyBudgetMs * 1_000_000) / max(1.0, $estimatedOperationNs)),
            ),
        );

        $samples = [];
        for ($i = 0; $i < $latencySamples; $i++) {
            $t0 = hrtime(true);
            $operation();
            $samples[] = (hrtime(true) - $t0) / 1000.0;
        }
        sort($samples);

        $throughputTargetNs = $config->throughputDurationMs * 1_000_000;
        $batchIterations = max(
            1,
            min(
                self::MAX_BATCH_ITERATIONS,
                (int) ceil($throughputTargetNs / max(1.0, $estimatedOperationNs)),
            ),
        );
        $throughputSamples = [];

        for ($repeat = 0; $repeat < $config->throughputRepeats; $repeat++) {
            gc_collect_cycles();
            $batchStart = hrtime(true);
            for ($i = 0; $i < $batchIterations; $i++) {
                $operation();
            }
            $batchElapsedNs = max(1, hrtime(true) - $batchStart);
            $throughputSamples[] = $batchIterations / ($batchElapsedNs / 1_000_000_000);

            $batchIterations = max(
                1,
                min(
                    self::MAX_BATCH_ITERATIONS,
                    (int) ceil($batchIterations * ($throughputTargetNs / $batchElapsedNs)),
                ),
            );
        }

        sort($throughputSamples);
        $opsPerSec = $this->median($throughputSamples);
        $absoluteDeviations = array_map(
            static fn (float $sample): float => abs($sample - $opsPerSec),
            $throughputSamples,
        );
        sort($absoluteDeviations);
        $relativeMad = $opsPerSec > 0.0 ? ($this->median($absoluteDeviations) / $opsPerSec) * 100.0 : 0.0;

        return new BenchmarkMetrics(
            p50Us: $this->percentile($samples, 0.50),
            p95Us: $this->percentile($samples, 0.95),
            p99Us: $this->percentile($samples, 0.99),
            opsPerSec: $opsPerSec,
            opsRelativeMadPercent: $relativeMad,
        );
    }

    /**
     * Calculate a nearest-rank percentile from an ordered sample set.
     *
     * @param list<float> $samples Ordered samples.
     * @param float $percentile Percentile in the inclusive range 0.0 through 1.0.
     *
     * @return float Percentile sample value.
     */
    private function percentile(array $samples, float $percentile): float
    {
        $index = max(0, min(count($samples) - 1, (int) ceil(count($samples) * $percentile) - 1));

        return $samples[$index];
    }

    /**
     * Calculate the median of an ordered numeric sample set.
     *
     * @param list<float> $samples Ordered samples.
     *
     * @return float Median sample value.
     */
    private function median(array $samples): float
    {
        $count = count($samples);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $samples[$middle]
            : ($samples[$middle - 1] + $samples[$middle]) / 2.0;
    }
}
