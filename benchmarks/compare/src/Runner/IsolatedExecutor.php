<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Runner;

use JsonException;
use Routir\Benchmarks\Model\AdapterResult;
use Routir\Benchmarks\Model\BenchmarkConfig;
use RuntimeException;

/**
 * Runs each adapter and route-count pair in a fresh PHP process.
 */
final readonly class IsolatedExecutor
{
    /**
     * @param string $workerPath Absolute benchmark worker script path.
     */
    public function __construct(
        private string $workerPath,
    ) {
    }

    /**
     * Execute one isolated benchmark worker and decode its result.
     *
     * @param string $adapterId Adapter registry identifier.
     * @param int $routeCount Number of generated routes.
     * @param BenchmarkConfig $config Adaptive measurement configuration.
     *
     * @return AdapterResult Completed isolated result.
     *
     * @throws JsonException When worker JSON is malformed.
     * @throws RuntimeException When the worker cannot start or exits unsuccessfully.
     */
    public function run(string $adapterId, int $routeCount, BenchmarkConfig $config): AdapterResult
    {
        $command = [
            PHP_BINARY,
            '-d',
            'opcache.enable_cli=' . ($this->isOpcacheEnabled() ? '1' : '0'),
            '-d',
            'opcache.jit=' . (ini_get('opcache.jit') ?: 'off'),
            '-d',
            'opcache.jit_buffer_size=' . (ini_get('opcache.jit_buffer_size') ?: '0'),
            $this->workerPath,
            '--adapter=' . $adapterId,
            '--routes=' . $routeCount,
            ...$config->toCliArguments(),
        ];

        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname($this->workerPath),
        );
        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Unable to start benchmark worker for adapter "%s".', $adapterId));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || $stdout === false || ($stderr !== false && trim($stderr) !== '')) {
            throw new RuntimeException(sprintf(
                'Benchmark worker for adapter "%s" failed with exit code %d: %s',
                $adapterId,
                $exitCode,
                $stderr === false ? 'unknown worker error' : trim($stderr),
            ));
        }

        /** @var array{
         *     adapter_id: string,
         *     label: string,
         *     category: string,
         *     route_count: int,
         *     static: array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float, ops_rmad_percent: float},
         *     dynamic: array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float, ops_rmad_percent: float},
         *     miss: array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float, ops_rmad_percent: float}
         * } $data
         */
        $data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

        return AdapterResult::fromArray($data);
    }

    /**
     * Determine whether CLI OPcache is active in the parent process.
     *
     * @return bool True when OPcache is enabled for CLI.
     */
    private function isOpcacheEnabled(): bool
    {
        $status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;

        return is_array($status) && ($status['opcache_enabled'] ?? false) === true;
    }
}
