<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Output;

use Routir\Benchmarks\Model\AdapterResult;
use Routir\Benchmarks\Model\BenchmarkConfig;

/**
 * Formats and prints live benchmark progress and final summary tables.
 */
final class ConsoleTableRenderer
{
    /**
     * Print PHP runtime, OPcache, and JIT environment metadata.
     *
     * @param BenchmarkConfig $config Adaptive measurement configuration.
     *
     * @return void
     */
    public function renderEnvironmentHeader(BenchmarkConfig $config): void
    {
        $opcacheEnabled = function_exists('opcache_get_status')
            && is_array(opcache_get_status())
            && (opcache_get_status()['opcache_enabled'] ?? false);

        echo sprintf(
            "PHP %s | OPcache: %s | JIT: %s | Mode: isolated worker per adapter/count\n",
            PHP_VERSION,
            $opcacheEnabled ? 'yes' : 'no',
            $opcacheEnabled ? (ini_get('opcache.jit') ?: 'off') : 'off',
        );
        echo sprintf(
            "Warmup: >=%d calls & >=%dms | Latency: %d-%d samples / %dms | Throughput: median %dx%dms (±rMAD)\n",
            $config->warmupMinIterations,
            $config->warmupMinDurationMs,
            $config->latencyMinSamples,
            $config->latencyMaxSamples,
            $config->latencyBudgetMs,
            $config->throughputRepeats,
            $config->throughputDurationMs,
        );
        echo str_repeat('=', 132) . "\n";
    }

    /**
     * Print section header for a route count group.
     *
     * @param int $routeCount Number of routes in this group.
     * @param int $dynamicIndex Worst-case dynamic route index.
     *
     * @return void
     */
    public function renderGroupHeader(int $routeCount, int $dynamicIndex): void
    {
        echo "\nRoutes: {$routeCount} (1/3 static, 1/3 int param, 1/3 multi-param | worst-case dynamic index={$dynamicIndex})\n";
        echo sprintf(
            "%-34s | %8s | %8s | %8s | %8s | %8s | %18s\n",
            'Router / Mode',
            'Static',
            'Dyn p50',
            'Dyn p95',
            'Dyn p99',
            'Miss p50',
            'Dyn ops/s ±rMAD',
        );
        echo sprintf("%-34s | %8s | %8s | %8s | %8s | %8s | %18s\n", '', '(us)', '(us)', '(us)', '(us)', '(us)', '');
        echo str_repeat('-', 114) . "\n";
    }

    /**
     * Print a single adapter result row as it finishes.
     *
     * @param AdapterResult $result Completed adapter result.
     *
     * @return void
     */
    public function renderLiveRow(AdapterResult $result): void
    {
        echo sprintf(
            "%-34s | %8.2f | %8.2f | %8.2f | %8.2f | %8.2f | %10.0f ±%4.1f%%\n",
            $result->label,
            $result->static->p50Us,
            $result->dynamic->p50Us,
            $result->dynamic->p95Us,
            $result->dynamic->p99Us,
            $result->miss->p50Us,
            $result->dynamic->opsPerSec,
            $result->dynamic->opsRelativeMadPercent,
        );
    }

    /**
     * Print a live progress line before running a benchmark adapter.
     *
     * @param int $routeCount Number of routes in the current group.
     * @param int $index 1-based adapter index.
     * @param int $total Total number of adapters in the group.
     * @param string $label Human-readable adapter label.
     *
     * @return void
     */
    public function renderProgress(int $routeCount, int $index, int $total, string $label): void
    {
        echo sprintf("[Routes=%d] (%d/%d) Benchmarking %s...\n", $routeCount, $index, $total, $label);
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Print the final formatted summary tables grouped by route count and sorted by throughput descending.
     *
     * @param array<int, list<AdapterResult>> $resultsByCount Results keyed by route count.
     *
     * @return void
     */
    public function renderFinalSummary(array $resultsByCount): void
    {
        $opcacheEnabled = function_exists('opcache_get_status')
            && is_array(opcache_get_status())
            && (opcache_get_status()['opcache_enabled'] ?? false);

        echo "\n" . str_repeat('=', 132) . "\n";
        echo sprintf(
            "FINAL BENCHMARK SUMMARY (sorted by Dyn ops/s desc) | PHP %s | OPcache: %s | JIT: %s\n",
            PHP_VERSION,
            $opcacheEnabled ? 'ON' : 'OFF',
            $opcacheEnabled ? (ini_get('opcache.jit') ?: 'off') : 'off',
        );
        echo str_repeat('=', 132) . "\n";

        foreach ($resultsByCount as $routeCount => $results) {
            $matchResults = array_values(array_filter(
                $results,
                static fn (AdapterResult $result): bool => $result->category === 'match',
            ));
            $psr7Results = array_values(array_filter(
                $results,
                static fn (AdapterResult $result): bool => $result->category === 'psr7',
            ));

            $this->renderResultTable(
                title: "Routes: {$routeCount} — Direct match",
                results: $matchResults,
            );
            $this->renderResultTable(
                title: "Routes: {$routeCount} — PSR-7 dispatch",
                results: $psr7Results,
            );
        }
    }

    /**
     * Render one semantically comparable result category.
     *
     * @param string $title Table title.
     * @param list<AdapterResult> $results Results from the same benchmark category.
     *
     * @return void
     */
    private function renderResultTable(string $title, array $results): void
    {
        if ($results === []) {
            return;
        }

        usort(
            $results,
            static fn (AdapterResult $a, AdapterResult $b): int => $b->dynamic->opsPerSec <=> $a->dynamic->opsPerSec,
        );

        echo "\n{$title}\n";
        echo sprintf(
            "  %-34s │ %11s │ %12s │ %12s │ %12s │ %13s │ %20s\n",
            'Router / Mode',
            'Static (µs)',
            'Dyn p50 (µs)',
            'Dyn p95 (µs)',
            'Dyn p99 (µs)',
            'Miss p50 (µs)',
            'Dyn ops/s ±rMAD',
        );
        echo sprintf(
            "  %s─┼─%s─┼─%s─┼─%s─┼─%s─┼─%s─┼─%s\n",
            str_repeat('─', 34),
            str_repeat('─', 11),
            str_repeat('─', 12),
            str_repeat('─', 12),
            str_repeat('─', 12),
            str_repeat('─', 13),
            str_repeat('─', 20),
        );

        foreach ($results as $result) {
            echo sprintf(
                "  %-34s │ %11.2f │ %12.2f │ %12.2f │ %12.2f │ %13.2f │ %12s ±%5.1f%%\n",
                $result->label,
                $result->static->p50Us,
                $result->dynamic->p50Us,
                $result->dynamic->p95Us,
                $result->dynamic->p99Us,
                $result->miss->p50Us,
                number_format($result->dynamic->opsPerSec, 0),
                $result->dynamic->opsRelativeMadPercent,
            );
        }
    }
}
