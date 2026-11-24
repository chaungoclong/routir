<?php

declare(strict_types=1);

use Routir\API\Router;
use Routir\API\RouterFactory;
use Routir\Compiler\CompileConfig;
use Routir\Compiler\Compiler;
use Routir\Contracts\RouterMode;
use Routir\DependencyInjection\SimpleContainer;
use Routir\Exception\CompilationException;
use Routir\Runtime\MinimalServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final class MethodHandlerDependency
{
    public function tag(): string
    {
        return 'dep';
    }
}

final class ResourceController
{
    public function __construct(private readonly MethodHandlerDependency $dep)
    {
    }

    public function store(ServerRequestInterface $request): string
    {
        return 'controller:post:' . $this->dep->tag() . ':' . $request->getMethod();
    }

    public function update(int $id): string
    {
        return 'controller:put:' . $id;
    }

    public function patchItem(int $id): string
    {
        return 'controller:patch:' . $id;
    }
}

final class DeleteInvokableHandler
{
    public function __construct(private readonly MethodHandlerDependency $dep)
    {
    }

    public function __invoke(int $id): string
    {
        return 'invokable:delete:' . $id . ':' . $this->dep->tag();
    }
}

test('all http methods and compilable handler types have full interpreted and compiled parity', function (): void {
    $base = sys_get_temp_dir() . '/routir_methods_handlers_' . uniqid('', true);
    $routesFile = $base . '/routes.php';
    $outDir = $base . '/out';
    mkdir($base, 0755, true);

    $routesCode = <<<'PHP'
<?php
declare(strict_types=1);

use Psr\Http\Message\ServerRequestInterface;

$prefix = 'cap';
$meta = ['v' => 1];

// GET: arrow function
$router->get('/items/{id:int}', static fn (int $id): string => 'arrow:get:' . $id)->name('items.show');

// POST: [Controller::class, 'store'] with DI + ServerRequestInterface
$router->post('/items', [ResourceController::class, 'store'])->name('items.store');

// PUT: [Controller::class, 'update']
$router->put('/items/{id:int}', [ResourceController::class, 'update'])->name('items.update');

// PATCH: [Controller::class, 'patchItem']
$router->patch('/items/{id:int}', [ResourceController::class, 'patchItem'])->name('items.patch');

// DELETE: Invokable class with DI
$router->delete('/items/{id:int}', DeleteInvokableHandler::class)->name('items.delete');

// OPTIONS: explicit OPTIONS route
$router->options('/items', static fn (): string => 'explicit:options');

// HEAD: explicit HEAD route
$router->head('/ping', static fn (): string => 'explicit:head');
$router->get('/ping', static fn (): string => 'explicit:get');

// match(): multiple methods including custom HTTP method, closure with scalar+array captures
$router->match(['GET', 'POST', 'PURGE'], '/cache/{key:slug}', static function (string $key, ServerRequestInterface $req) use ($prefix, $meta): string {
    return $prefix . ':' . $meta['v'] . ':' . $req->getMethod() . ':' . $key;
});

// any(): standard methods
$router->any('/any-endpoint', static fn (ServerRequestInterface $req): string => 'any:' . $req->getMethod());
PHP;
    file_put_contents($routesFile, $routesCode);

    $dep = new MethodHandlerDependency();
    $container = new SimpleContainer([
        MethodHandlerDependency::class => $dep,
        ResourceController::class => static fn (): ResourceController => new ResourceController($dep),
        DeleteInvokableHandler::class => static fn (): DeleteInvokableHandler => new DeleteInvokableHandler($dep),
    ]);

    try {
        $config = new CompileConfig(
            inputs: $routesFile,
            outputDir: $outDir,
            mode: RouterMode::Compiled,
            optimizationLevel: 2,
        );
        new Compiler()->compile($config);

        $interpreted = RouterFactory::create(
            mode: RouterMode::Interpreted,
            routes: $routesFile,
            container: $container,
        );
        $compiled = RouterFactory::fromConfig($config, container: $container);

        $cases = [
            ['GET', '/items/10', 200, 'arrow:get:10'],
            ['HEAD', '/items/10', 200, ''], // implicit HEAD -> GET
            ['POST', '/items', 200, 'controller:post:dep:POST'],
            ['PUT', '/items/10', 200, 'controller:put:10'],
            ['PATCH', '/items/10', 200, 'controller:patch:10'],
            ['DELETE', '/items/10', 200, 'invokable:delete:10:dep'],
            ['OPTIONS', '/items', 200, 'explicit:options'],
            ['HEAD', '/ping', 200, 'explicit:head'],
            ['GET', '/ping', 200, 'explicit:get'],
            ['GET', '/cache/home-page', 200, 'cap:1:GET:home-page'],
            ['POST', '/cache/home-page', 200, 'cap:1:POST:home-page'],
            ['PURGE', '/cache/home-page', 200, 'cap:1:PURGE:home-page'],
            ['DELETE', '/cache/home-page', 405, ''],
            ['GET', '/any-endpoint', 200, 'any:GET'],
            ['POST', '/any-endpoint', 200, 'any:POST'],
            ['PUT', '/any-endpoint', 200, 'any:PUT'],
            ['PATCH', '/any-endpoint', 200, 'any:PATCH'],
            ['DELETE', '/any-endpoint', 200, 'any:DELETE'],
            ['OPTIONS', '/any-endpoint', 200, 'any:OPTIONS'],
            ['HEAD', '/any-endpoint', 200, 'any:HEAD'],
        ];

        foreach ($cases as [$method, $target, $expectedStatus, $expectedBody]) {
            $r1 = $interpreted->dispatch(new MinimalServerRequest($method, $target));
            $r2 = $compiled->dispatch(new MinimalServerRequest($method, $target));

            expect($r1->getStatusCode())->toBe($expectedStatus, "Interpreted status for $method $target")
                ->and((string) $r1->getBody())->toBe($expectedBody, "Interpreted body for $method $target")
                ->and($r2->getStatusCode())->toBe($expectedStatus, "Compiled status for $method $target")
                ->and((string) $r2->getBody())->toBe($expectedBody, "Compiled body for $method $target");
        }
    } finally {
        exec('rm -rf ' . escapeshellarg($base));
    }
});

test('interpreted mode supports object and callable handlers while compiled mode rejects uncompilable live instances', function (): void {
    $invokableObj = new class {
        public function __invoke(string $name): string
        {
            return 'obj-invokable:' . $name;
        }
    };

    $controllerObj = new class {
        public function handle(int $id): string
        {
            return 'obj-method:' . $id;
        }
    };

    $router = new Router();
    $router->get('/obj/{name}', $invokableObj);
    $router->post('/obj/{id:int}', [$controllerObj, 'handle']);

    $r1 = $router->dispatch(new MinimalServerRequest('GET', '/obj/alice'));
    $r2 = $router->dispatch(new MinimalServerRequest('POST', '/obj/5'));

    expect((string) $r1->getBody())->toBe('obj-invokable:alice')
        ->and((string) $r2->getBody())->toBe('obj-method:5');

    $outDir = sys_get_temp_dir() . '/routir_uncompilable_' . uniqid('', true);
    try {
        expect(static fn () => new Compiler()->compileToDirectory($router->getCollection(), $outDir))
            ->toThrow(CompilationException::class);
    } finally {
        exec('rm -rf ' . escapeshellarg($outDir));
    }
});
