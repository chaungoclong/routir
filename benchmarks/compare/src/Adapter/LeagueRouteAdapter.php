<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Adapter;

use League\Route\Http\Exception\NotFoundException;
use League\Route\Router;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use Psr\Http\Message\ResponseInterface;
use Routir\Benchmarks\Contract\RouterBenchmarkAdapterInterface;
use Routir\Benchmarks\Model\RouteSpec;

/**
 * Benchmark adapter for league/route full PSR-7/PSR-15 request dispatch.
 */
final class LeagueRouteAdapter implements RouterBenchmarkAdapterInterface
{
    private Router $router;

    /** @var array<string, ServerRequest> */
    private array $requests = [];

    #[Override]
    public function id(): string
    {
        return 'league-route-psr7';
    }

    #[Override]
    public function label(): string
    {
        return 'League Route (PSR-7 dispatch)';
    }

    #[Override]
    public function category(): string
    {
        return 'psr7';
    }

    #[Override]
    public function prepare(array $specs, string $workDir): void
    {
        $router = new Router();
        foreach ($specs as $spec) {
            $router->get(
                $spec->toFastRoutePattern(),
                static fn (): ResponseInterface => new Response(200, [], 'ok'),
            );
        }

        $this->router = $router;
    }

    #[Override]
    public function execute(string $target): bool
    {
        $request = $this->requests[$target] ??= new ServerRequest('GET', $target);

        try {
            return $this->router->dispatch($request)->getStatusCode() === 200;
        } catch (NotFoundException) {
            return false;
        }
    }
}
