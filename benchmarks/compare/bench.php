<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use FastRoute\Dispatcher;
use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Events\Dispatcher as IlluminateEvents;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Router as IlluminateRouter;
use League\Route\Http\Exception\NotFoundException as LeagueNotFoundException;
use League\Route\Router as LeagueRouter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as NyholmResponse;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Routir\API\Router as RoutirRouter;
use Routir\API\RouterFactory;
use Routir\Compiler\Compiler;
use Routir\Compiler\CompilerOptions;
use Routir\Contracts\RouterMode;
use Routir\Interpreter\ReferenceMatcher;
use Routir\Runtime\CompiledRouter;
use Routir\Semantic\SemanticAnalyzer;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Symfony\Component\Routing\RouteCollection as SymfonyRouteCollection;

use function FastRoute\cachedDispatcher;
use function FastRoute\simpleDispatcher;

/**
 * Cross-framework & library router comparison benchmark.
 *
 * Compares Routir (Interpreted, Compiled -O0/-O1/-O2, and Compiled -O2 PSR-7 dispatch)
 * against FastRoute, Symfony Routing, Laravel (illuminate/routing), and League Route.
 */

/**
 * @param callable(): mixed $fn
 *
 * @return array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float}
 */
function measure(callable $fn, int $iterations = 2000, int $warmup = 100): array
{
    for ($i = 0; $i < $warmup; $i++) {
        $fn();
    }

    $samples = [];
    $totalStart = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $t0 = hrtime(true);
        $fn();
        $samples[] = (hrtime(true) - $t0) / 1000.0;
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

/**
 * @return list<array{type: 'static'|'int'|'multi', path_routir: string, path_std: string, wheres: array<string, string>, name: string}>
 */
function generateRouteSpecs(int $count): array
{
    $specs = [];
    for ($i = 0; $i < $count; $i++) {
        if ($i % 3 === 0) {
            $specs[] = [
                'type' => 'static',
                'path_routir' => "/static/group{$i}/item",
                'path_std' => "/static/group{$i}/item",
                'wheres' => [],
                'name' => "s.$i",
            ];
        } elseif ($i % 3 === 1) {
            $specs[] = [
                'type' => 'int',
                'path_routir' => "/api/v1/resource{$i}/{id:int}",
                'path_std' => "/api/v1/resource{$i}/{id}",
                'wheres' => ['id' => '[0-9]+'],
                'name' => "d.$i",
            ];
        } else {
            $specs[] = [
                'type' => 'multi',
                'path_routir' => "/api/v1/org/{org:alpha}/repo{$i}/{slug:slug}",
                'path_std' => "/api/v1/org/{org}/repo{$i}/{slug}",
                'wheres' => ['org' => '[a-zA-Z]+', 'slug' => '[a-zA-Z0-9_-]+'],
                'name' => "m.$i",
            ];
        }
    }

    return $specs;
}

/**
 * @param list<array{type: 'static'|'int'|'multi', path_routir: string, path_std: string, wheres: array<string, string>, name: string}> $specs
 */
function buildFastRoutePattern(array $spec): string
{
    $pattern = $spec['path_std'];
    foreach ($spec['wheres'] as $param => $regex) {
        $pattern = str_replace('{' . $param . '}', '{' . $param . ':' . $regex . '}', $pattern);
    }

    return $pattern;
}

/**
 * @param list<array{label: string, static: float, dyn: float, miss: float, ops: float}> $rows
 */
function printBoxTable(string $title, array $rows): void
{
    echo "\n{$title}\n";
    echo sprintf(
        "  %-34s │ %11s │ %12s │ %13s │ %11s\n",
        'Router / Mode',
        'Static (µs)',
        'Dyn p50 (µs)',
        'Miss p50 (µs)',
        'Dyn ops/s',
    );
    echo sprintf(
        "  %s─┼─%s─┼─%s─┼─%s─┼─%s\n",
        str_repeat('─', 34),
        str_repeat('─', 11),
        str_repeat('─', 12),
        str_repeat('─', 13),
        str_repeat('─', 11),
    );
    foreach ($rows as $row) {
        echo sprintf(
            "  %-34s │ %11.2f │ %12.2f │ %13.2f │ %11s\n",
            $row['label'],
            $row['static'],
            $row['dyn'],
            $row['miss'],
            number_format($row['ops'], 0),
        );
    }
}

/**
 * @param array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float} $static
 * @param array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float} $dyn
 * @param array{p50_us: float, p95_us: float, p99_us: float, ops_sec: float} $miss
 * @param list<array{label: string, static: float, dyn: float, miss: float, ops: float}> $collector
 */
function printRow(string $label, array $static, array $dyn, array $miss, array &$collector = []): void
{
    $collector[] = [
        'label' => $label,
        'static' => $static['p50_us'],
        'dyn' => $dyn['p50_us'],
        'miss' => $miss['p50_us'],
        'ops' => $dyn['ops_sec'],
    ];

    echo sprintf(
        "%-34s | %8.2f | %8.2f | %8.2f | %10.0f\n",
        $label,
        $static['p50_us'],
        $dyn['p50_us'],
        $miss['p50_us'],
        $dyn['ops_sec'],
    );
}

$opcacheEnabled = function_exists('opcache_get_status')
    && is_array(opcache_get_status())
    && (opcache_get_status()['opcache_enabled'] ?? false);

echo sprintf(
    "PHP %s | OPcache: %s | JIT: %s\n",
    PHP_VERSION,
    $opcacheEnabled ? 'yes' : 'no',
    ini_get('opcache.jit') ?: 'off',
);
echo str_repeat('=', 84) . "\n";

$baseTmp = sys_get_temp_dir() . '/routir_compare_' . getmypid();
mkdir($baseTmp, 0755, true);

/** @var array<int, list<array{label: string, static: float, dyn: float, miss: float, ops: float}>> $summaryByCount */
$summaryByCount = [];

try {
    foreach ([100, 500] as $count) {
        $rows = [];
        $specs = generateRouteSpecs($count);
        $hitIdx = $count === 100 ? 98 : 497;

        $staticTarget = '/static/group0/item';
        $dynTarget = "/api/v1/org/acme/repo{$hitIdx}/hello-world";
        $missTarget = '/not/found/anywhere';

        echo "\nRoutes: {$count} (1/3 static, 1/3 int param, 1/3 multi-param | worst-case dynamic index={$hitIdx})\n";
        echo sprintf(
            "%-34s | %8s | %8s | %8s | %10s\n",
            'Router / Mode',
            'Static',
            'Dyn p50',
            'Miss p50',
            'Dyn ops/s',
        );
        echo sprintf("%-34s | %8s | %8s | %8s | %10s\n", '', '(us)', '(us)', '(us)', '');
        echo str_repeat('-', 84) . "\n";

        // 1. Routir (Interpreted + Compiled -O0/-O1/-O2)
        $routir = new RoutirRouter();
        foreach ($specs as $spec) {
            $routir->get($spec['path_routir'], static fn (): string => 'ok')->name($spec['name']);
        }
        $semantic = new SemanticAnalyzer()->analyze($routir->getCollection());
        $refMatcher = new ReferenceMatcher($semantic);

        printRow(
            'Routir (Interpreted match)',
            measure(static fn () => $refMatcher->match('GET', $staticTarget)),
            measure(static fn () => $refMatcher->match('GET', $dynTarget)),
            measure(static fn () => $refMatcher->match('GET', $missTarget)),
            $rows,
        );

        $compiler = new Compiler();
        foreach ([0, 1, 2] as $opt) {
            $dir = "{$baseTmp}/routir_{$count}_O{$opt}";
            $compiler->compileToDirectory(
                $routir->getCollection(),
                $dir,
                new CompilerOptions(optimizationLevel: $opt, lintGeneratedPhp: false, runSmokeTest: false),
            );
            $compiled = CompiledRouter::load($dir);

            printRow(
                "Routir (Compiled -O{$opt} match)",
                measure(static fn () => $compiled->matchRequest('GET', $staticTarget)),
                measure(static fn () => $compiled->matchRequest('GET', $dynTarget)),
                measure(static fn () => $compiled->matchRequest('GET', $missTarget)),
                $rows,
            );
        }

        // 2. FastRoute (Simple & Cached)
        $fastRouteDef = static function (\FastRoute\RouteCollector $r) use ($specs): void {
            foreach ($specs as $spec) {
                $r->addRoute('GET', buildFastRoutePattern($spec), $spec['name']);
            }
        };

        $frSimple = simpleDispatcher($fastRouteDef);
        printRow(
            'FastRoute (simpleDispatcher)',
            measure(static fn () => $frSimple->dispatch('GET', $staticTarget)),
            measure(static fn () => $frSimple->dispatch('GET', $dynTarget)),
            measure(static fn () => $frSimple->dispatch('GET', $missTarget)),
            $rows,
        );

        $frCacheFile = "{$baseTmp}/fastroute_{$count}.php";
        // Warm cache file once before measuring
        cachedDispatcher($fastRouteDef, ['cacheFile' => $frCacheFile]);
        $frCached = cachedDispatcher($fastRouteDef, ['cacheFile' => $frCacheFile]);
        printRow(
            'FastRoute (cachedDispatcher)',
            measure(static fn () => $frCached->dispatch('GET', $staticTarget)),
            measure(static fn () => $frCached->dispatch('GET', $dynTarget)),
            measure(static fn () => $frCached->dispatch('GET', $missTarget)),
            $rows,
        );

        // 3. Symfony Routing (UrlMatcher & CompiledUrlMatcher)
        $sfRoutes = new SymfonyRouteCollection();
        foreach ($specs as $spec) {
            $sfRoutes->add(
                $spec['name'],
                new SymfonyRoute(
                    path: $spec['path_std'],
                    requirements: $spec['wheres'],
                    methods: ['GET'],
                ),
            );
        }
        $sfContext = new RequestContext();
        $sfMatcher = new UrlMatcher($sfRoutes, $sfContext);
        $sfMatch = static function (UrlMatcher $matcher, string $path): ?array {
            try {
                return $matcher->match($path);
            } catch (ResourceNotFoundException) {
                return null;
            }
        };
        printRow(
            'Symfony Routing (UrlMatcher)',
            measure(static fn () => $sfMatch($sfMatcher, $staticTarget)),
            measure(static fn () => $sfMatch($sfMatcher, $dynTarget)),
            measure(static fn () => $sfMatch($sfMatcher, $missTarget)),
            $rows,
        );

        $sfCompiledData = new CompiledUrlMatcherDumper($sfRoutes)->getCompiledRoutes();
        $sfCompiledMatcher = new CompiledUrlMatcher($sfCompiledData, $sfContext);
        $sfCompiledMatch = static function (CompiledUrlMatcher $matcher, string $path): ?array {
            try {
                return $matcher->match($path);
            } catch (ResourceNotFoundException) {
                return null;
            }
        };
        printRow(
            'Symfony Routing (Compiled)',
            measure(static fn () => $sfCompiledMatch($sfCompiledMatcher, $staticTarget)),
            measure(static fn () => $sfCompiledMatch($sfCompiledMatcher, $dynTarget)),
            measure(static fn () => $sfCompiledMatch($sfCompiledMatcher, $missTarget)),
            $rows,
        );

        // 4. Laravel (illuminate/routing: standard & CompiledRouteCollection)
        $ilContainer = new IlluminateContainer();
        $ilEvents = new IlluminateEvents($ilContainer);
        $ilRouter = new IlluminateRouter($ilEvents, $ilContainer);
        foreach ($specs as $spec) {
            $route = $ilRouter->get($spec['path_std'], static fn (): string => 'ok')->name($spec['name']);
            if ($spec['wheres'] !== []) {
                $route->where($spec['wheres']);
            }
        }
        $ilRoutes = $ilRouter->getRoutes();
        $ilRoutes->refreshNameLookups();

        $ilStaticReq = IlluminateRequest::create($staticTarget, 'GET');
        $ilDynReq = IlluminateRequest::create($dynTarget, 'GET');
        $ilMissReq = IlluminateRequest::create($missTarget, 'GET');
        $ilMatch = static function ($collection, IlluminateRequest $req): mixed {
            try {
                return $collection->match($req);
            } catch (NotFoundHttpException) {
                return null;
            }
        };
        printRow(
            'Laravel Routing (RouteCollection)',
            measure(static fn () => $ilMatch($ilRoutes, $ilStaticReq)),
            measure(static fn () => $ilMatch($ilRoutes, $ilDynReq)),
            measure(static fn () => $ilMatch($ilRoutes, $ilMissReq)),
            $rows,
        );

        $ilCompiledArray = $ilRoutes->compile();
        $ilCompiledRoutes = new CompiledRouteCollection($ilCompiledArray['compiled'], $ilCompiledArray['attributes']);
        $ilCompiledRoutes->setRouter($ilRouter)->setContainer($ilContainer);
        printRow(
            'Laravel Routing (Compiled)',
            measure(static fn () => $ilMatch($ilCompiledRoutes, $ilStaticReq)),
            measure(static fn () => $ilMatch($ilCompiledRoutes, $ilDynReq)),
            measure(static fn () => $ilMatch($ilCompiledRoutes, $ilMissReq)),
            $rows,
        );

        echo str_repeat('-', 84) . "\n";

        // 5. Full PSR-7 Dispatch comparison: Routir (-O2) vs League Route
        $psrFactory = new Psr17Factory();
        $routirPsr = RouterFactory::create(
            mode: RouterMode::Compiled,
            artifact: "{$baseTmp}/routir_{$count}_O2",
            responseFactory: $psrFactory,
            streamFactory: $psrFactory,
        );
        $leagueRouter = new LeagueRouter();
        foreach ($specs as $spec) {
            $leagueRouter->get(
                buildFastRoutePattern($spec),
                static fn (): ResponseInterface => new NyholmResponse(200, [], 'ok'),
            );
        }

        $psrStaticReq = new ServerRequest('GET', $staticTarget);
        $psrDynReq = new ServerRequest('GET', $dynTarget);
        $psrMissReq = new ServerRequest('GET', $missTarget);

        printRow(
            'Routir (Compiled -O2 PSR-7)',
            measure(static fn () => $routirPsr->dispatch($psrStaticReq)),
            measure(static fn () => $routirPsr->dispatch($psrDynReq)),
            measure(static fn () => $routirPsr->dispatch($psrMissReq)),
            $rows,
        );

        $leagueDispatch = static function (LeagueRouter $router, ServerRequest $req): ?ResponseInterface {
            try {
                return $router->dispatch($req);
            } catch (LeagueNotFoundException) {
                return null;
            }
        };
        printRow(
            'League Route (PSR-7 dispatch)',
            measure(static fn () => $leagueDispatch($leagueRouter, $psrStaticReq)),
            measure(static fn () => $leagueDispatch($leagueRouter, $psrDynReq)),
            measure(static fn () => $leagueDispatch($leagueRouter, $psrMissReq)),
            $rows,
        );

        $summaryByCount[$count] = $rows;
    }

    echo "\n" . str_repeat('=', 93) . "\n";
    echo "FINAL BENCHMARK SUMMARY\n";
    echo str_repeat('=', 93) . "\n";
    foreach ($summaryByCount as $count => $rows) {
        printBoxTable("Routes: {$count}", $rows);
    }
} finally {
    exec('rm -rf ' . escapeshellarg($baseTmp));
}
