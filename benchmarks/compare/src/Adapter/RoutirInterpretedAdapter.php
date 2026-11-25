<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Adapter;

use Override;
use Routir\API\Router;
use Routir\Benchmarks\Contract\RouterBenchmarkAdapterInterface;
use Routir\Benchmarks\Model\RouteSpec;
use Routir\Contracts\MatchStatus;
use Routir\Exception\SemanticException;
use Routir\Interpreter\ReferenceMatcher;
use Routir\Semantic\SemanticAnalyzer;

/**
 * Benchmark adapter for Routir in Interpreted mode (ReferenceMatcher).
 */
final class RoutirInterpretedAdapter implements RouterBenchmarkAdapterInterface
{
    private ReferenceMatcher $matcher;

    #[Override]
    public function id(): string
    {
        return 'routir-interpreted';
    }

    #[Override]
    public function label(): string
    {
        return 'Routir (Interpreted match)';
    }

    #[Override]
    public function category(): string
    {
        return 'match';
    }

    /**
     * @param list<RouteSpec> $specs Route definitions to register.
     * @param string $workDir Isolated temporary directory (unused in interpreted mode).
     *
     * @return void
     *
     * @throws SemanticException When route semantic analysis fails.
     */
    #[Override]
    public function prepare(array $specs, string $workDir): void
    {
        $router = new Router();
        foreach ($specs as $spec) {
            $router->get($spec->routirPath, static fn (): string => 'ok')->name($spec->name);
        }

        $semantic = new SemanticAnalyzer()->analyze($router->getCollection());
        $this->matcher = new ReferenceMatcher($semantic);
    }

    #[Override]
    public function execute(string $target): bool
    {
        return $this->matcher->match('GET', $target)->status === MatchStatus::Found;
    }
}
