<?php

declare(strict_types=1);

use Routir\API\RouteLoader;
use Routir\API\RouterFactory;
use Routir\Compiler\CompileConfig;
use Routir\Compiler\Compiler;
use Routir\Contracts\RouterMode;
use Routir\Exception\RoutirException;
use Routir\Runtime\MinimalServerRequest;

test('loads routes from directory and multiple files deterministically', function (): void {
    $base = sys_get_temp_dir() . '/routir_loader_' . uniqid('', true);
    $routesDir = $base . '/routes';
    $outDir = $base . '/out';
    mkdir($routesDir, 0755, true);

    file_put_contents($routesDir . '/01-web.php', "<?php\n\$router->get('/', static fn (): string => 'web')->name('web.home');\n");
    file_put_contents($routesDir . '/02-api.php', "<?php\n\$router->get('/api/ping', static fn (): string => 'pong')->name('api.ping');\n");

    try {
        $files = RouteLoader::resolveFiles($routesDir);
        expect($files)->toHaveCount(2);

        $config = new CompileConfig(
            inputs: $routesDir,
            outputDir: $outDir,
            mode: RouterMode::Auto,
            optimizationLevel: 2,
        );

        // Auto falls back to Interpreted before build
        $interpreted = RouterFactory::fromConfig($config);
        expect((string) $interpreted->dispatch(new MinimalServerRequest('GET', '/'))->getBody())->toBe('web')
            ->and((string) $interpreted->dispatch(new MinimalServerRequest('GET', '/api/ping'))->getBody())->toBe('pong');

        // Build from config
        $build = new Compiler()->compile($config);
        expect($build['buildId'])->not->toBeEmpty();

        // Auto now loads Compiled artifacts
        $compiled = RouterFactory::fromConfig($config);
        expect((string) $compiled->dispatch(new MinimalServerRequest('GET', '/api/ping'))->getBody())->toBe('pong')
            ->and($compiled->route('web.home'))->toBe('/');
    } finally {
        exec('rm -rf ' . escapeshellarg($base));
    }
});

test('loads config from php file', function (): void {
    $base = sys_get_temp_dir() . '/routir_cfg_' . uniqid('', true);
    mkdir($base, 0755, true);
    $cfgFile = $base . '/routir.php';

    file_put_contents($cfgFile, "<?php\nreturn ['input' => ['a.php', 'b.php'], 'output' => '/tmp/art', 'mode' => 'compiled', 'optimizationLevel' => 1];\n");

    try {
        $cfg = CompileConfig::fromFile($cfgFile);
        expect($cfg->inputs)->toBe(['a.php', 'b.php'])
            ->and($cfg->outputDir)->toBe('/tmp/art')
            ->and($cfg->mode)->toBe(RouterMode::Compiled)
            ->and($cfg->optimizationLevel)->toBe(1);
    } finally {
        exec('rm -rf ' . escapeshellarg($base));
    }
});

test('throws when config input path does not exist', function (): void {
    expect(static fn () => RouteLoader::resolveFiles('/non/existent/routes.php'))
        ->toThrow(RoutirException::class);
});
