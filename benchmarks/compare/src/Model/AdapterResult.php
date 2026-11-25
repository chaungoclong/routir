<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Model;

/**
 * Aggregated benchmark result for a single adapter and route count.
 */
final readonly class AdapterResult
{
    /**
     * @param string $adapterId Unique adapter identifier.
     * @param string $label Human-readable router/mode label.
     * @param string $category Benchmark category (`match` or `psr7`).
     * @param int $routeCount Number of registered routes.
     * @param BenchmarkMetrics $static Static route hit metrics.
     * @param BenchmarkMetrics $dynamic Worst-case dynamic route hit metrics.
     * @param BenchmarkMetrics $miss Unmatched (404) route metrics.
     */
    public function __construct(
        public string $adapterId,
        public string $label,
        public string $category,
        public int $routeCount,
        public BenchmarkMetrics $static,
        public BenchmarkMetrics $dynamic,
        public BenchmarkMetrics $miss,
    ) {
    }

    /**
     * @return array{
     *     adapter_id: string,
     *     label: string,
     *     category: string,
     *     route_count: int,
     *     static: array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float, ops_rmad_percent: float},
     *     dynamic: array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float, ops_rmad_percent: float},
     *     miss: array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float, ops_rmad_percent: float}
     * }
     */
    public function toArray(): array
    {
        return [
            'adapter_id' => $this->adapterId,
            'label' => $this->label,
            'category' => $this->category,
            'route_count' => $this->routeCount,
            'static' => $this->static->toArray(),
            'dynamic' => $this->dynamic->toArray(),
            'miss' => $this->miss->toArray(),
        ];
    }

    /**
     * @param array{
     *     adapter_id: string,
     *     label: string,
     *     category: string,
     *     route_count: int,
     *     static: array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float, ops_rmad_percent: float},
     *     dynamic: array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float, ops_rmad_percent: float},
     *     miss: array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float, ops_rmad_percent: float}
     * } $data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            adapterId: (string) $data['adapter_id'],
            label: (string) $data['label'],
            category: (string) $data['category'],
            routeCount: (int) $data['route_count'],
            static: BenchmarkMetrics::fromArray($data['static']),
            dynamic: BenchmarkMetrics::fromArray($data['dynamic']),
            miss: BenchmarkMetrics::fromArray($data['miss']),
        );
    }
}
