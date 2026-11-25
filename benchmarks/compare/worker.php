<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Routir\Benchmarks\Adapter\AdapterRegistry;
use Routir\Benchmarks\Model\BenchmarkConfig;
use Routir\Benchmarks\Runner\SequentialExecutor;

/**
 * Isolated benchmark worker. Intended to be launched by bench.php only.
 *
 * @return void
 *
 * @throws Throwable When adapter setup, measurement, or result serialization fails.
 */
function runWorker(): void
{
    $options = getopt('', [
        'adapter:',
        'routes:',
        'iterations:',
        'latency-min-samples:',
        'latency-ms:',
        'warmup:',
        'warmup-ms:',
        'throughput-ms:',
        'repeats:',
    ]);

    $adapterId = is_string($options['adapter'] ?? null) ? $options['adapter'] : '';
    $routeCount = max(3, (int) ($options['routes'] ?? 0));
    $latencyMinSamples = max(20, (int) ($options['latency-min-samples'] ?? 100));
    $config = new BenchmarkConfig(
        latencyMaxSamples: max($latencyMinSamples, (int) ($options['iterations'] ?? 10_000)),
        latencyMinSamples: $latencyMinSamples,
        latencyBudgetMs: max(10, (int) ($options['latency-ms'] ?? 250)),
        warmupMinIterations: max(1, (int) ($options['warmup'] ?? 128)),
        warmupMinDurationMs: max(0, (int) ($options['warmup-ms'] ?? 50)),
        throughputDurationMs: max(10, (int) ($options['throughput-ms'] ?? 500)),
        throughputRepeats: max(3, (int) ($options['repeats'] ?? 7)),
    );

    $adapter = new AdapterRegistry()->find($adapterId);
    $result = new SequentialExecutor()->run(
        adapter: $adapter,
        routeCount: $routeCount,
        config: $config,
    );

    echo json_encode($result->toArray(), JSON_THROW_ON_ERROR);
}

runWorker();
