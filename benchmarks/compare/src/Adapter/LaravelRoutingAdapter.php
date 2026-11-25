<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Adapter;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\AbstractRouteCollection;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Router;
use Override;
use Routir\Benchmarks\Contract\RouterBenchmarkAdapterInterface;
use Routir\Benchmarks\Model\RouteSpec;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Benchmark adapter for illuminate/routing (RouteCollection or CompiledRouteCollection).
 */
final class LaravelRoutingAdapter implements RouterBenchmarkAdapterInterface
{
    private AbstractRouteCollection $collection;

    /** @var array<string, Request> */
    private array $requests = [];

    /**
     * @param bool $compiled Whether to use Laravel's CompiledRouteCollection.
     */
    public function __construct(
        private readonly bool $compiled,
    ) {
    }

    #[Override]
    public function id(): string
    {
        return $this->compiled ? 'laravel-compiled' : 'laravel-collection';
    }

    #[Override]
    public function label(): string
    {
        return $this->compiled ? 'Laravel Routing (Compiled)' : 'Laravel Routing (RouteCollection)';
    }

    #[Override]
    public function category(): string
    {
        return 'match';
    }

    #[Override]
    public function prepare(array $specs, string $workDir): void
    {
        $container = new Container();
        $events = new Dispatcher($container);
        $router = new Router($events, $container);

        foreach ($specs as $spec) {
            $route = $router->get($spec->standardPath, static fn (): string => 'ok')->name($spec->name);
            if ($spec->wheres !== []) {
                $route->where($spec->wheres);
            }
        }

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        if ($this->compiled) {
            $compiled = $routes->compile();
            $compiledCollection = new CompiledRouteCollection($compiled['compiled'], $compiled['attributes']);
            $compiledCollection->setRouter($router)->setContainer($container);
            $this->collection = $compiledCollection;

            return;
        }

        $this->collection = $routes;
    }

    #[Override]
    public function execute(string $target): bool
    {
        $request = $this->requests[$target] ??= Request::create($target, 'GET');

        try {
            $this->collection->match($request);

            return true;
        } catch (NotFoundHttpException) {
            return false;
        }
    }
}
