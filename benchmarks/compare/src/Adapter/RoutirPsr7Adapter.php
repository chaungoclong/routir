<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Adapter;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Override;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Routir\API\Router;
use Routir\API\RouterFactory;
use Routir\Benchmarks\Contract\RouterBenchmarkAdapterInterface;
use Routir\Benchmarks\Model\RouteSpec;
use Routir\Compiler\Compiler;
use Routir\Compiler\CompilerOptions;
use Routir\Contracts\RouterInterface;
use Routir\Contracts\RouterMode;
use Routir\Exception\CompilationException;
use Routir\Exception\DispatchException;
use Routir\Exception\RoutirException;

/**
 * Benchmark adapter for Routir Compiled -O2 full PSR-7 request dispatch.
 */
final class RoutirPsr7Adapter implements RouterBenchmarkAdapterInterface
{
    private RouterInterface $router;

    /** @var array<string, ServerRequest> */
    private array $requests = [];

    #[Override]
    public function id(): string
    {
        return 'routir-psr7';
    }

    #[Override]
    public function label(): string
    {
        return 'Routir (Compiled -O2 PSR-7)';
    }

    #[Override]
    public function category(): string
    {
        return 'psr7';
    }

    /**
     * @param list<RouteSpec> $specs Route definitions to register.
     * @param string $workDir Isolated temporary directory for compiled artifacts.
     *
     * @return void
     *
     * @throws CompilationException When route compilation fails.
     * @throws RoutirException When compiled artifacts cannot be written or loaded.
     */
    #[Override]
    public function prepare(array $specs, string $workDir): void
    {
        $router = new Router();
        foreach ($specs as $spec) {
            $router->get($spec->routirPath, static fn (): string => 'ok')->name($spec->name);
        }

        $artifactDir = $workDir . '/routir_psr7';
        new Compiler()->compileToDirectory(
            collection: $router->getCollection(),
            outputDir: $artifactDir,
            options: new CompilerOptions(
                optimizationLevel: 2,
                lintGeneratedPhp: false,
                runSmokeTest: false,
            ),
        );

        $psr17 = new Psr17Factory();
        $this->router = RouterFactory::create(
            mode: RouterMode::Compiled,
            artifact: $artifactDir,
            responseFactory: $psr17,
            streamFactory: $psr17,
        );
    }

    /**
     * @param string $target Request URI path.
     *
     * @return bool
     *
     * @throws DispatchException When handler dispatch fails.
     * @throws ContainerExceptionInterface When container resolution fails.
     * @throws NotFoundExceptionInterface When a container service is missing.
     */
    #[Override]
    public function execute(string $target): bool
    {
        $request = $this->requests[$target] ??= new ServerRequest('GET', $target);

        return $this->router->dispatch($request)->getStatusCode() === 200;
    }
}
