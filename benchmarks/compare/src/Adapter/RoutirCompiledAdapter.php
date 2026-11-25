<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Adapter;

use Override;
use Routir\API\Router;
use Routir\Benchmarks\Contract\RouterBenchmarkAdapterInterface;
use Routir\Benchmarks\Model\RouteSpec;
use Routir\Compiler\Compiler;
use Routir\Compiler\CompilerOptions;
use Routir\Contracts\MatchStatus;
use Routir\Exception\CompilationException;
use Routir\Exception\RoutirException;
use Routir\Runtime\CompiledRouter;

/**
 * Benchmark adapter for Routir in AOT Compiled mode at a specific optimization level.
 */
final class RoutirCompiledAdapter implements RouterBenchmarkAdapterInterface
{
    private CompiledRouter $router;

    /**
     * @param int $optimizationLevel Compiler optimization level (0, 1, or 2).
     */
    public function __construct(
        private readonly int $optimizationLevel,
    ) {
    }

    #[Override]
    public function id(): string
    {
        return "routir-compiled-o{$this->optimizationLevel}";
    }

    #[Override]
    public function label(): string
    {
        return "Routir (Compiled -O{$this->optimizationLevel} match)";
    }

    #[Override]
    public function category(): string
    {
        return 'match';
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

        $artifactDir = $workDir . '/routir_o' . $this->optimizationLevel;
        new Compiler()->compileToDirectory(
            collection: $router->getCollection(),
            outputDir: $artifactDir,
            options: new CompilerOptions(
                optimizationLevel: $this->optimizationLevel,
                lintGeneratedPhp: false,
                runSmokeTest: false,
            ),
        );

        $this->router = CompiledRouter::load($artifactDir);
    }

    #[Override]
    public function execute(string $target): bool
    {
        return $this->router->matchRequest('GET', $target)->status === MatchStatus::Found;
    }
}
