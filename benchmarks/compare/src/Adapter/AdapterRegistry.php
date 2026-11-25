<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Adapter;

use InvalidArgumentException;
use Routir\Benchmarks\Contract\RouterBenchmarkAdapterInterface;

/**
 * Central registry of all available router benchmark adapters.
 */
final class AdapterRegistry
{
    /**
     * Return all registered adapters in display order.
     *
     * @return list<RouterBenchmarkAdapterInterface>
     */
    public function all(): array
    {
        return [
            new RoutirInterpretedAdapter(),
            new RoutirCompiledAdapter(optimizationLevel: 0),
            new RoutirCompiledAdapter(optimizationLevel: 1),
            new RoutirCompiledAdapter(optimizationLevel: 2),
            new FastRouteAdapter(cached: false),
            new FastRouteAdapter(cached: true),
            new SymfonyRoutingAdapter(compiled: false),
            new SymfonyRoutingAdapter(compiled: true),
            new LaravelRoutingAdapter(compiled: false),
            new LaravelRoutingAdapter(compiled: true),
            new RoutirPsr7Adapter(),
            new LeagueRouteAdapter(),
        ];
    }

    /**
     * Find a registered adapter by its unique identifier.
     *
     * @param string $id Adapter identifier.
     *
     * @return RouterBenchmarkAdapterInterface
     *
     * @throws InvalidArgumentException When no adapter matches the given ID.
     */
    public function find(string $id): RouterBenchmarkAdapterInterface
    {
        $found = array_find(
            $this->all(),
            static fn (RouterBenchmarkAdapterInterface $adapter): bool => $adapter->id() === $id,
        );

        if ($found === null) {
            throw new InvalidArgumentException(sprintf('Unknown benchmark adapter "%s".', $id));
        }

        return $found;
    }
}
