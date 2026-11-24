<?php

declare(strict_types=1);

use Routir\API\Router;
use Routir\API\RouterFactory;
use Routir\Compiler\Compiler;
use Routir\Compiler\CompilerOptions;
use Routir\Contracts\RouterMode;
use Routir\DependencyInjection\SimpleContainer;
use Routir\Exception\RouteNotFoundException;
use Routir\Exception\UrlGenerationException;
use Routir\Runtime\MinimalServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

test('full observable parity across optimization levels', function (int $optLevel): void {
    $routesFile = sys_get_temp_dir() . '/routir_parity_routes_' . uniqid('', true) . '.php';
    $artifactDir = sys_get_temp_dir() . '/routir_parity_art_' . uniqid('', true);

    $routesCode = <<<'PHP'
<?php
declare(strict_types=1);
use Routir\API\Router;

$prefix = 'v2';

$router->middlewareAlias('tag', 'TestTagMiddleware');

$router->get('/', static fn (): string => 'home')->name('home');
$router->get('/users', static fn (): string => 'users.index')->name('users.index');
$router->get('/users/{id:int}', static function (int $id) use ($prefix): string {
    return $prefix . ':user:' . $id;
})->name('users.show')->middleware('tag');

$router->prefix('/api')->name('api.')->group(static function (Router $r): void {
    $r->get('/posts/{slug:slug?}', static fn (string $slug = 'latest'): string => 'post:' . $slug)
        ->defaults('slug', 'latest')
        ->name('posts.show');
    $r->get('/files/{path:*}', static fn (string $path): string => 'file:' . $path)
        ->name('files');
});

$router->get('/{any}/fallback-branch', static fn (string $any): string => 'any:' . $any);
$router->get('/{user:alpha}/profile', static fn (string $user): string => 'alpha:' . $user);

$router->fallback(static fn (): string => 'custom-404');
PHP;
    file_put_contents($routesFile, $routesCode);

    $mw = new class implements MiddlewareInterface {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            return $handler->handle($request)->withHeader('X-Tag', 'yes');
        }
    };

    $container = new SimpleContainer([
        'TestTagMiddleware' => $mw,
    ]);

    try {
        $interpreted = RouterFactory::create(
            mode: RouterMode::Interpreted,
            routes: $routesFile,
            container: $container,
        );

        $router = new Router();
        require $routesFile;
        $compiler = new Compiler();
        $compiler->compileToDirectory(
            $router->getCollection(),
            $artifactDir,
            new CompilerOptions(optimizationLevel: $optLevel),
            [$routesFile],
        );

        $compiled = RouterFactory::create(
            mode: RouterMode::Compiled,
            artifact: $artifactDir,
            container: $container,
        );

        $requests = [
            ['GET', '/'],
            ['GET', '/users'],
            ['GET', '/users/42'],
            ['HEAD', '/users/42'],
            ['POST', '/users/42'],
            ['OPTIONS', '/users/42'],
            ['GET', '/api/posts'],
            ['GET', '/api/posts/hello-world'],
            ['GET', '/api/files/a/b%20c/d.txt'],
            ['GET', '/john/fallback-branch'],
            ['GET', '/123/fallback-branch'],
            ['GET', '/john/profile'],
            ['GET', '/unknown'],
            ['GET', '/%ZZ'],
        ];

        foreach ($requests as [$method, $target]) {
            $r1 = $interpreted->matchRequest($method, $target);
            $r2 = $compiled->matchRequest($method, $target);
            expect($r2->status)->toBe($r1->status, "MatchStatus mismatch at O$optLevel for $method $target")
                ->and($r2->routeId)->toBe($r1->routeId, "RouteId mismatch at O$optLevel for $method $target")
                ->and($r2->params)->toBe($r1->params, "Params mismatch at O$optLevel for $method $target")
                ->and($r2->allowedMethods)->toBe($r1->allowedMethods, "Allowed mismatch at O$optLevel for $method $target")
                ->and($r2->headFallback)->toBe($r1->headFallback, "HeadFallback mismatch at O$optLevel for $method $target");

            $d1 = $interpreted->dispatch(new MinimalServerRequest($method, $target));
            $d2 = $compiled->dispatch(new MinimalServerRequest($method, $target));
            expect($d2->getStatusCode())->toBe($d1->getStatusCode(), "HTTP status mismatch at O$optLevel for $method $target")
                ->and((string) $d2->getBody())->toBe((string) $d1->getBody(), "HTTP body mismatch at O$optLevel for $method $target")
                ->and($d2->getHeaderLine('Allow'))->toBe($d1->getHeaderLine('Allow'))
                ->and($d2->getHeaderLine('X-Tag'))->toBe($d1->getHeaderLine('X-Tag'));
        }

        expect($compiled->has('users.show'))->toBe($interpreted->has('users.show'))
            ->and($compiled->has('missing'))->toBe($interpreted->has('missing'))
            ->and($compiled->route('users.show', ['id' => 7, 'q' => 'a b']))->toBe($interpreted->route('users.show', ['id' => 7, 'q' => 'a b']))
            ->and($compiled->route('api.posts.show'))->toBe($interpreted->route('api.posts.show'))
            ->and($compiled->route('api.posts.show', ['slug' => 'my-post']))->toBe($interpreted->route('api.posts.show', ['slug' => 'my-post']))
            ->and($compiled->route('api.files', ['path' => 'a/b c']))->toBe($interpreted->route('api.files', ['path' => 'a/b c']));

        expect(static fn () => $compiled->route('nope'))->toThrow(RouteNotFoundException::class);
        expect(static fn () => $compiled->route('users.show', ['id' => 'bad']))->toThrow(UrlGenerationException::class);
    } finally {
        if (is_file($routesFile)) {
            unlink($routesFile);
        }
        exec('rm -rf ' . escapeshellarg($artifactDir));
    }
/** @phpstan-ignore method.notFound */
})->with([
    'O0' => [0],
    'O1' => [1],
    'O2' => [2],
]);

test('compiled artifacts run in fresh process without source or compiler', function (): void {
    $routesFile = sys_get_temp_dir() . '/routir_fresh_routes_' . uniqid('', true) . '.php';
    $artifactDir = sys_get_temp_dir() . '/routir_fresh_art_' . uniqid('', true);
    $runnerFile = sys_get_temp_dir() . '/routir_fresh_runner_' . uniqid('', true) . '.php';

    file_put_contents($routesFile, "<?php\n\$router->get('/hi/{name:alpha}', static fn (string \$name): string => 'hi ' . \$name)->name('hi');\n");

    try {
        $router = new Router();
        require $routesFile;
        new Compiler()->compileToDirectory($router->getCollection(), $artifactDir, new CompilerOptions(optimizationLevel: 2), [$routesFile]);

        unlink($routesFile);

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $runnerCode = <<<PHP
<?php
require '{$autoload}';
use Routir\API\RouterFactory;
use Routir\Contracts\RouterMode;
use Routir\Runtime\MinimalServerRequest;

\$router = RouterFactory::create(mode: RouterMode::Compiled, artifact: '{$artifactDir}');
\$res = \$router->dispatch(new MinimalServerRequest('GET', '/hi/Alice'));
echo \$res->getStatusCode() . '|' . (string) \$res->getBody() . '|' . \$router->route('hi', ['name' => 'Bob']);
PHP;
        file_put_contents($runnerFile, $runnerCode);
        $out = [];
        $code = 0;
        exec('php ' . escapeshellarg($runnerFile), $out, $code);
        expect($code)->toBe(0)
            ->and(implode("\n", $out))->toBe('200|hi Alice|/hi/Bob');
    } finally {
        if (is_file($routesFile)) {
            unlink($routesFile);
        }
        if (is_file($runnerFile)) {
            unlink($runnerFile);
        }
        exec('rm -rf ' . escapeshellarg($artifactDir));
    }
});
