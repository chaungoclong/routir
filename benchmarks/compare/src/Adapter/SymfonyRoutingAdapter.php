<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Adapter;

use Override;
use Routir\Benchmarks\Contract\RouterBenchmarkAdapterInterface;
use Routir\Benchmarks\Model\RouteSpec;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Benchmark adapter for symfony/routing (UrlMatcher or CompiledUrlMatcher).
 */
final class SymfonyRoutingAdapter implements RouterBenchmarkAdapterInterface
{
    private UrlMatcherInterface $matcher;

    /**
     * @param bool $compiled Whether to use Symfony's AOT CompiledUrlMatcher.
     */
    public function __construct(
        private readonly bool $compiled,
    ) {
    }

    #[Override]
    public function id(): string
    {
        return $this->compiled ? 'symfony-compiled' : 'symfony-matcher';
    }

    #[Override]
    public function label(): string
    {
        return $this->compiled ? 'Symfony Routing (Compiled)' : 'Symfony Routing (UrlMatcher)';
    }

    #[Override]
    public function category(): string
    {
        return 'match';
    }

    #[Override]
    public function prepare(array $specs, string $workDir): void
    {
        $routes = new RouteCollection();
        foreach ($specs as $spec) {
            $routes->add(
                $spec->name,
                new Route(
                    path: $spec->standardPath,
                    requirements: $spec->wheres,
                    methods: ['GET'],
                ),
            );
        }

        $context = new RequestContext();
        if ($this->compiled) {
            $compiledRoutes = new CompiledUrlMatcherDumper($routes)->getCompiledRoutes();
            $this->matcher = new CompiledUrlMatcher($compiledRoutes, $context);

            return;
        }

        $this->matcher = new UrlMatcher($routes, $context);
    }

    #[Override]
    public function execute(string $target): bool
    {
        try {
            $this->matcher->match($target);

            return true;
        } catch (ResourceNotFoundException) {
            return false;
        }
    }
}
