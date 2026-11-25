<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Runner;

use Routir\Benchmarks\Contract\RouterBenchmarkAdapterInterface;
use Routir\Benchmarks\Generator\RouteSpecGenerator;
use Routir\Benchmarks\Model\AdapterResult;
use RuntimeException;

/**
 * Executes each benchmark adapter sequentially with isolated temp directories and GC cleanup.
 */
final readonly class SequentialExecutor
{
    public function __construct(
        private RouteSpecGenerator $generator = new RouteSpecGenerator(),
        private LatencySampler $sampler = new LatencySampler(),
    ) {
    }

    /**
     * Prepare, verify, and measure a single adapter for the given route count.
     *
     * @param RouterBenchmarkAdapterInterface $adapter Router adapter to benchmark.
     * @param int $routeCount Number of routes to register.
     * @param int $iterations Number of timed iterations per scenario.
     * @param int $warmup Number of warmup iterations per scenario.
     *
     * @return AdapterResult
     *
     * @throws RuntimeException When the adapter fails correctness verification.
     */
    public function run(
        RouterBenchmarkAdapterInterface $adapter,
        int $routeCount,
        int $iterations,
        int $warmup,
    ): AdapterResult {
        $specs = $this->generator->generate($routeCount);
        $targets = $this->generator->resolveTargets($routeCount);

        $workDir = sys_get_temp_dir() . '/routir_bench_' . $adapter->id() . '_' . getmypid();
        mkdir($workDir, 0755, true);

        try {
            $adapter->prepare($specs, $workDir);

            // Verify observable correctness before timing so no adapter can misconfigure routes
            if (!$adapter->execute($targets['static'])) {
                throw new RuntimeException(sprintf('Adapter "%s" failed static hit verification.', $adapter->id()));
            }
            if (!$adapter->execute($targets['dynamic'])) {
                throw new RuntimeException(sprintf('Adapter "%s" failed dynamic hit verification.', $adapter->id()));
            }
            if ($adapter->execute($targets['miss'])) {
                throw new RuntimeException(sprintf('Adapter "%s" unexpectedly matched miss target.', $adapter->id()));
            }

            $staticTarget = $targets['static'];
            $dynamicTarget = $targets['dynamic'];
            $missTarget = $targets['miss'];

            return new AdapterResult(
                adapterId: $adapter->id(),
                label: $adapter->label(),
                category: $adapter->category(),
                routeCount: $routeCount,
                static: $this->sampler->sample(
                    operation: static fn (): bool => $adapter->execute($staticTarget),
                    iterations: $iterations,
                    warmup: $warmup,
                ),
                dynamic: $this->sampler->sample(
                    operation: static fn (): bool => $adapter->execute($dynamicTarget),
                    iterations: $iterations,
                    warmup: $warmup,
                ),
                miss: $this->sampler->sample(
                    operation: static fn (): bool => $adapter->execute($missTarget),
                    iterations: $iterations,
                    warmup: $warmup,
                ),
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($workDir));
            gc_collect_cycles();
        }
    }
}
