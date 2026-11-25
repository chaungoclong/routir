<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Routir\Benchmarks\Adapter\AdapterRegistry;
use Routir\Benchmarks\Model\AdapterResult;
use Routir\Benchmarks\Output\ConsoleTableRenderer;
use Routir\Benchmarks\Runner\SequentialExecutor;

/**
 * Cross-framework router comparison benchmark entrypoint.
 *
 * Usage:
 *   php bench.php [--routes=100,500] [--iterations=2000] [--warmup=100] [--only=id1,id2]
 */
$options = getopt('', ['routes::', 'iterations::', 'warmup::', 'only::']);

$routesRaw = is_string($options['routes'] ?? null) ? $options['routes'] : '100,500';
$routeCounts = array_values(array_filter(
    array_map(static fn (string $v): int => (int) trim($v), explode(',', $routesRaw)),
    static fn (int $v): bool => $v >= 3,
));
if ($routeCounts === []) {
    $routeCounts = [100, 500];
}

$iterations = max(1, (int) ($options['iterations'] ?? 2000));
$warmup = max(0, (int) ($options['warmup'] ?? 100));

$onlyIds = [];
if (is_string($options['only'] ?? null) && trim($options['only']) !== '') {
    $onlyIds = array_map(static fn (string $v): string => trim($v), explode(',', $options['only']));
}

$registry = new AdapterRegistry();
$adapters = $registry->all();
if ($onlyIds !== []) {
    $adapters = array_values(array_filter(
        $adapters,
        static fn ($adapter): bool => in_array($adapter->id(), $onlyIds, true),
    ));
}

$executor = new SequentialExecutor();
$renderer = new ConsoleTableRenderer();

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
            adapter: $adapter,
            routeCount: $count,
            iterations: $iterations,
            warmup: $warmup,
        );
    }

    $resultsByCount[$count] = $groupResults;
}

$renderer->renderFinalSummary($resultsByCount);
