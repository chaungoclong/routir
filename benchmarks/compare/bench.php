<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Routir\Benchmarks\Adapter\AdapterRegistry;
use Routir\Benchmarks\Model\AdapterResult;
use Routir\Benchmarks\Model\BenchmarkConfig;
use Routir\Benchmarks\Output\ConsoleTableRenderer;
use Routir\Benchmarks\Runner\IsolatedExecutor;

/**
 * Cross-framework router comparison benchmark entrypoint.
 *
 * Usage:
 *   php bench.php [--routes=100,500] [--iterations=10000] [--warmup=128]
 *                 [--latency-ms=250] [--throughput-ms=500] [--repeats=7]
 *                 [--only=id1,id2]
 */
$options = getopt('', [
    'routes::',
    'iterations::',
    'latency-min-samples::',
    'latency-ms::',
    'warmup::',
    'warmup-ms::',
    'throughput-ms::',
    'repeats::',
    'only::',
]);

$routesRaw = is_string($options['routes'] ?? null) ? $options['routes'] : '100,500';
$routeCounts = array_values(array_filter(
    array_map(static fn (string $v): int => (int) trim($v), explode(',', $routesRaw)),
    static fn (int $v): bool => $v >= 3,
));
if ($routeCounts === []) {
    $routeCounts = [100, 500];
}

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

$onlyIds = [];
if (is_string($options['only'] ?? null) && trim($options['only']) !== '') {
    $onlyIds = array_map(static fn (string $v): string => trim($v), explode(',', $options['only']));
}

$registry = new AdapterRegistry();
$adapters = $onlyIds === []
    ? $registry->all()
    : array_map($registry->find(...), $onlyIds);

$executor = new IsolatedExecutor(__DIR__ . '/worker.php');
$renderer = new ConsoleTableRenderer();
$renderer->renderEnvironmentHeader($config);

/** @var array<int, list<AdapterResult>> $resultsByCount */
$resultsByCount = [];
$totalAdapters = count($adapters);

foreach ($routeCounts as $count) {
    $groupResults = [];

    foreach ($adapters as $idx => $adapter) {
        $renderer->renderProgress(
            routeCount: $count,
            index: $idx + 1,
            total: $totalAdapters,
            label: $adapter->label(),
        );
        $groupResults[] = $executor->run(
            adapterId: $adapter->id(),
            routeCount: $count,
            config: $config,
        );
    }

    $resultsByCount[$count] = $groupResults;
}

$renderer->renderFinalSummary($resultsByCount);
