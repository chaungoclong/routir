<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Contract;

use Routir\Benchmarks\Model\RouteSpec;

/**
 * Contract for a router benchmark adapter executed inside an isolated worker process.
 */
interface RouterBenchmarkAdapterInterface
{
    /**
     * Unique CLI identifier for this adapter.
     *
     * @return string
     */
    public function id(): string;

    /**
     * Display label printed in benchmark tables.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Benchmark category (`match` for route matching only, `psr7` for full PSR-7 dispatch).
     *
     * @return string
     */
    public function category(): string;

    /**
     * Register routes and perform any ahead-of-time compilation/caching before timing starts.
     *
     * @param list<RouteSpec> $specs Route definitions to register.
     * @param string $workDir Isolated temporary directory for compiled/cached artifacts.
     *
     * @return void
     */
    public function prepare(array $specs, string $workDir): void;

    /**
     * Execute a single request target and return whether a route matched.
     *
     * @param string $target Request URI path.
     *
     * @return bool True if matched (hit), false if not found (404 miss).
     */
    public function execute(string $target): bool;
}
