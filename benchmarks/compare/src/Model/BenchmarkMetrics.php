<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Model;

/**
 * Latency and throughput measurements for a single benchmark scenario.
 */
final readonly class BenchmarkMetrics
{
    /**
     * @param float $p50Us Median latency in microseconds.
     * @param float $p95Us 95th percentile latency in microseconds.
     * @param float $p99Us 99th percentile latency in microseconds.
     * @param float $opsPerSec Median throughput in operations per second.
     * @param float $opsRelativeMadPercent Relative median absolute deviation across throughput batches.
     */
    public function __construct(
        public float $p50Us,
        public float $p95Us,
        public float $p99Us,
        public float $opsPerSec,
        public float $opsRelativeMadPercent,
    ) {
    }

    /**
     * @return array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float, ops_rmad_percent: float}
     */
    public function toArray(): array
    {
        return [
            'p50_us' => $this->p50Us,
            'p95_us' => $this->p95Us,
            'p99_us' => $this->p99Us,
            'ops_sec' => $this->opsPerSec,
            'ops_rmad_percent' => $this->opsRelativeMadPercent,
        ];
    }

    /**
     * @param array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float, ops_rmad_percent: float} $data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            p50Us: (float) $data['p50_us'],
            p95Us: (float) $data['p95_us'],
            p99Us: (float) $data['p99_us'],
            opsPerSec: (float) $data['ops_sec'],
            opsRelativeMadPercent: (float) $data['ops_rmad_percent'],
        );
    }
}
