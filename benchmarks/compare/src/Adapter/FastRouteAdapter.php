<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Adapter;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Override;
use Routir\Benchmarks\Contract\RouterBenchmarkAdapterInterface;
use Routir\Benchmarks\Model\RouteSpec;

use function FastRoute\cachedDispatcher;
use function FastRoute\simpleDispatcher;

/**
 * Benchmark adapter for nikic/fast-route (simple or file-cached dispatcher).
 */
final class FastRouteAdapter implements RouterBenchmarkAdapterInterface
{
    private Dispatcher $dispatcher;

    /**
     * @param bool $cached Whether to use FastRoute's cachedDispatcher.
     */
    public function __construct(
        private readonly bool $cached,
    ) {
    }

    #[Override]
    public function id(): string
    {
        return $this->cached ? 'fastroute-cached' : 'fastroute-simple';
    }

    #[Override]
    public function label(): string
    {
        return $this->cached ? 'FastRoute (cachedDispatcher)' : 'FastRoute (simpleDispatcher)';
    }

    #[Override]
    public function category(): string
    {
        return 'match';
    }

    #[Override]
    public function prepare(array $specs, string $workDir): void
    {
        $definition = static function (RouteCollector $collector) use ($specs): void {
            foreach ($specs as $spec) {
                $collector->addRoute('GET', $spec->toFastRoutePattern(), $spec->name);
            }
        };

        if ($this->cached) {
            $cacheFile = $workDir . '/fastroute.php';
            // Warm cache file before loading the dispatcher under test
            cachedDispatcher($definition, ['cacheFile' => $cacheFile]);
            $this->dispatcher = cachedDispatcher($definition, ['cacheFile' => $cacheFile]);

            return;
        }

        $this->dispatcher = simpleDispatcher($definition);
    }

    #[Override]
    public function execute(string $target): bool
    {
        return $this->dispatcher->dispatch('GET', $target)[0] === Dispatcher::FOUND;
    }
}
