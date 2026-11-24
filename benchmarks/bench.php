<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Routir\API\Router;
use Routir\Compiler\Compiler;
use Routir\Compiler\CompilerOptions;
use Routir\Interpreter\ReferenceMatcher;
use Routir\Runtime\CompiledRouter;
use Routir\Semantic\SemanticAnalyzer;

/**
 * Routir Benchmark Suite
 * Measures build time, generated size, and match latency (p50/p95/p99, ops/sec)
 * across route counts and optimization levels.
 */
function buildRoutes(int $count): Router
{
    $router = new Router();
    for ($i = 0; $i < $count; $i++) {
        if ($i % 3 === 0) {
            $router->get("/static/group{$i}/item", static fn (): string => 'ok')->name("s.$i");
        } elseif ($i % 3 === 1) {
            $router->get("/api/v1/resource{$i}/{id:int}", static fn (int $id): string => (string) $id)->name("d.$i");
        } else {
            $router->get("/api/v1/org/{org:alpha}/repo{$i}/{slug:slug}", static fn (string $org, string $slug): string => $org . $slug)->name("m.$i");
        }
    }

    return $router;
}

/**
 * @param callable(): mixed $fn
 * @return array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float}
 */
function measureLatency(callable $fn, int $iterations = 2000): array
{
    // Warmup
    for ($i = 0; $i < 100; $i++) {
        $fn();
    }

    $samples = [];
    $totalStart = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $t0 = hrtime(true);
        $fn();
        $samples[] = (hrtime(true) - $t0) / 1000.0; // microseconds
    }
    $totalSec = (hrtime(true) - $totalStart) / 1e9;
    sort($samples);

    return [
        'p50_us' => $samples[(int) ($iterations * 0.50)],
        'p95_us' => $samples[(int) ($iterations * 0.95)],
        'p99_us' => $samples[(int) ($iterations * 0.99)],
        'ops_sec' => $iterations / max(1e-9, $totalSec),
    ];
}

echo sprintf("PHP %s | OPcache enabled: %s | JIT: %s\n",
    PHP_VERSION,
    (function_exists('opcache_get_status') && is_array(opcache_get_status()) && (opcache_get_status()['opcache_enabled'] ?? false)) ? 'yes' : 'no',
    ini_get('opcache.jit') ?: 'off',
);
echo str_repeat('-', 90) . "\n";

$compiler = new Compiler();
$baseTmp = sys_get_temp_dir() . '/routir_bench_' . getmypid();

try {
    foreach ([10, 100, 500] as $count) {
        $router = buildRoutes($count);
        $semantic = new SemanticAnalyzer()->analyze($router->getCollection());
        $refMatcher = new ReferenceMatcher($semantic);

        $hitIdx = match ($count) {
            10 => 8,
            100 => 98,
            default => 497,
        };
        $hitTarget = '/api/v1/org/acme/repo' . $hitIdx . '/hello-world';
        $staticTarget = '/static/group0/item';
        $missTarget = '/not/found/anywhere';

        $refHit = measureLatency(static fn () => $refMatcher->match('GET', $hitTarget));
        $refStatic = measureLatency(static fn () => $refMatcher->match('GET', $staticTarget));
        $refMiss = measureLatency(static fn () => $refMatcher->match('GET', $missTarget));

        echo sprintf("Routes=%d | Interpreted  | static p50=%.2fus | dyn p50=%.2fus | miss p50=%.2fus\n",
            $count, $refStatic['p50_us'], $refHit['p50_us'], $refMiss['p50_us']);

        foreach ([0, 1, 2] as $opt) {
            $dir = $baseTmp . "_{$count}_O{$opt}";
            $t0 = hrtime(true);
            $compiler->compileToDirectory(
                $router->getCollection(),
                $dir,
                new CompilerOptions(optimizationLevel: $opt, lintGeneratedPhp: false, runSmokeTest: false),
            );
            $buildMs = (hrtime(true) - $t0) / 1e6;
            $matcherSize = filesize($dir . '/current/matcher.php') ?: 0;
            $compiled = CompiledRouter::load($dir);

            $cHit = measureLatency(static fn () => $compiled->matchRequest('GET', $hitTarget));
            $cStatic = measureLatency(static fn () => $compiled->matchRequest('GET', $staticTarget));
            $cMiss = measureLatency(static fn () => $compiled->matchRequest('GET', $missTarget));

            echo sprintf("Routes=%d | Compiled -O%d | build=%.1fms size=%dB | static p50=%.2fus | dyn p50=%.2fus | miss p50=%.2fus\n",
                $count, $opt, $buildMs, $matcherSize, $cStatic['p50_us'], $cHit['p50_us'], $cMiss['p50_us']);
        }
        echo str_repeat('-', 90) . "\n";
    }
} finally {
    exec('rm -rf ' . escapeshellarg($baseTmp . '*'));
}
