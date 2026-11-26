<?php

declare(strict_types=1);

namespace Routir\Compiler;

use JsonException;
use Random\RandomException;
use ReflectionException;
use Routir\API\RouteCollection;
use Routir\API\RouteLoader;
use Routir\API\Router;
use Routir\Backend\PHP\PHPHandlerEmitter;
use Routir\Backend\PHP\PHPMatcherEmitter;
use Routir\Backend\PHP\PHPMetadataEmitter;
use Routir\Backend\PHP\PHPPipelineEmitter;
use Routir\Backend\PHP\PHPUrlGeneratorEmitter;
use Routir\Exception\CompilationException;
use Routir\Exception\RoutirException;
use Routir\Exception\SemanticException;
use Routir\Frontend\Handler\ClosureExtractor;
use Routir\IR\HIR\HIRModule;
use Routir\IR\LIR\LIRModule;
use Routir\IR\MIR\MIRModule;
use Routir\Runtime\CompiledRouter;
use Routir\Semantic\SemanticAnalyzer;
use Routir\Transform\Lowering\HIRBuilder;
use Routir\Transform\Lowering\LIRLowering;
use Routir\Transform\Lowering\MIRLowering;
use Routir\Transform\Optimization\BlockCleanupPass;
use Routir\Transform\Optimization\StaticDispatchPass;
use Routir\Transform\Optimization\TrieFactoringPass;

final class Compiler
{
    /**
     * Compile routes from a RouteCollection into HIR, MIR, and LIR modules.
     *
     * @param RouteCollection $collection Collected route definitions.
     * @param CompilerOptions $options Compilation and optimization options.
     *
     * @return array{hir: HIRModule, mir: MIRModule, lir: LIRModule, context: CompilationContext}
     *
     * @throws CompilationException When IR lowering or verification fails.
     * @throws SemanticException When semantic or handler analysis fails.
     * @throws ReflectionException When handler reflection fails.
     */
    public function compileIR(RouteCollection $collection, CompilerOptions $options = new CompilerOptions()): array
    {
        $semantic = new SemanticAnalyzer()->analyze($collection);
        $hir = new HIRBuilder()->build($semantic);
        $mir = new MIRLowering()->lower($hir);
        $lir = new LIRLowering()->lower($mir);

        $context = new CompilationContext($options, $semantic->diagnostics);
        $passManager = $this->buildPassManager($options);
        $lir = $passManager->run($lir, $context);

        return [
            'hir' => $hir,
            'mir' => $mir,
            'lir' => $lir,
            'context' => $context,
        ];
    }

    /**
     * Compile routes from a CompileConfig into its configured output directory.
     *
     * @param CompileConfig $config Input paths, output directory, and optimization options.
     *
     * @return array{buildId: string, releaseDir: string, context: CompilationContext}
     *
     * @throws CompilationException When route compilation or IR verification fails.
     * @throws SemanticException When semantic or handler analysis fails.
     * @throws ReflectionException When handler reflection fails.
     * @throws JsonException When JSON encoding of manifests fails.
     * @throws RandomException When staging directory suffix generation fails.
     * @throws RoutirException When loading routes, locking, or publishing artifacts fails.
     */
    public function compile(CompileConfig $config): array
    {
        $router = new Router();
        $inputFiles = RouteLoader::loadInto($router, $config->inputs);

        return $this->compileToDirectory(
            $router->getCollection(),
            $config->outputDir,
            new CompilerOptions(
                optimizationLevel: $config->optimizationLevel,
                allowScalarCaptures: $config->allowScalarCaptures,
            ),
            $inputFiles,
        );
    }

    /**
     * Compile a RouteCollection into verified PHP artifacts in the given output directory.
     *
     * @param RouteCollection $collection Collected route definitions.
     * @param string $outputDir Target output directory for releases and current symlink.
     * @param CompilerOptions $options Compilation and optimization options.
     * @param list<string> $inputFiles Source route file paths for manifest hashing.
     *
     * @return array{buildId: string, releaseDir: string, context: CompilationContext}
     *
     * @throws CompilationException When IR lowering, closure extraction, or verification fails.
     * @throws SemanticException When semantic or handler analysis fails.
     * @throws ReflectionException When handler reflection fails.
     * @throws JsonException When JSON encoding of manifests or source maps fails.
     * @throws RandomException When staging directory suffix generation fails.
     * @throws RoutirException When directory creation, locking, linting, or atomic publishing fails.
     */
    public function compileToDirectory(
        RouteCollection $collection,
        string $outputDir,
        CompilerOptions $options = new CompilerOptions(),
        array $inputFiles = [],
    ): array {
        $compiled = $this->compileIR($collection, $options);
        $hir = $compiled['hir'];
        $lir = $compiled['lir'];

        $matcherPhp = new PHPMatcherEmitter()->emit($lir);
        $handlersPhp = new PHPHandlerEmitter(new ClosureExtractor($options->allowScalarCaptures))->emit($hir);
        $pipelinesPhp = new PHPPipelineEmitter()->emit($hir);
        $urlGenPhp = new PHPUrlGeneratorEmitter()->emit($hir);
        $metaEmitter = new PHPMetadataEmitter();
        $metadataPhp = $metaEmitter->emitMetadata($hir);
        $sourceMapJson = json_encode($metaEmitter->buildSourceMap($hir), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        $artifacts = [
            'matcher.php' => $matcherPhp,
            'handlers.php' => $handlersPhp,
            'pipelines.php' => $pipelinesPhp,
            'url-generator.php' => $urlGenPhp,
            'metadata.php' => $metadataPhp,
            'source-map.json' => $sourceMapJson,
        ];

        $artifactHashes = array_map(
            static fn (string $content): string => hash('sha256', $content),
            $artifacts,
        );

        $inputHashes = [];
        foreach ($inputFiles as $file) {
            if (is_file($file)) {
                $inputHashes[$file] = hash_file('sha256', $file) ?: '';
            }
        }

        $manifestPayload = [
            'schemaVersion' => CompilerOptions::SCHEMA_VERSION,
            'compilerVersion' => CompilerOptions::COMPILER_VERSION,
            'phpTarget' => PHP_VERSION,
            'optimizationLevel' => $options->optimizationLevel,
            'allowScalarCaptures' => $options->allowScalarCaptures,
            'inputHashes' => $inputHashes,
            'artifactHashes' => $artifactHashes,
        ];
        $buildId = substr(hash('sha256', json_encode($manifestPayload, JSON_THROW_ON_ERROR)), 0, 16);
        $manifestPayload['buildId'] = $buildId;
        $artifacts['manifest.json'] = json_encode($manifestPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new RoutirException(sprintf('Unable to create output directory "%s".', $outputDir));
        }

        $lockFile = $outputDir . '/.routir.lock';
        $lockHandle = fopen($lockFile, 'c+');
        if ($lockHandle === false) {
            throw new RoutirException(sprintf('Unable to open lock file "%s".', $lockFile));
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RoutirException('Failed to acquire build lock.');
            }

            $stagingDir = $outputDir . '/.staging_' . getmypid() . '_' . bin2hex(random_bytes(4));
            if (!mkdir($stagingDir, 0755, true)) {
                throw new RoutirException(sprintf('Unable to create staging directory "%s".', $stagingDir));
            }

            try {
                foreach ($artifacts as $name => $content) {
                    file_put_contents($stagingDir . '/' . $name, $content);
                    chmod($stagingDir . '/' . $name, 0644);
                }

                if ($options->lintGeneratedPhp) {
                    $this->lintDirectory($stagingDir);
                }

                if ($options->runSmokeTest) {
                    CompiledRouter::load($stagingDir);
                }

                $releasesDir = $outputDir . '/releases';
                if (!is_dir($releasesDir) && !mkdir($releasesDir, 0755, true) && !is_dir($releasesDir)) {
                    throw new RoutirException(sprintf('Unable to create releases directory "%s".', $releasesDir));
                }

                $releaseDir = $releasesDir . '/' . $buildId;
                if (is_dir($releaseDir)) {
                    $this->removeDirectory($releaseDir);
                }

                if (!rename($stagingDir, $releaseDir)) {
                    throw new RoutirException(sprintf('Failed to publish release directory "%s".', $releaseDir));
                }

                // Atomic pointer switch via temporary symlink + rename
                $tmpLink = $outputDir . '/.current_' . getmypid();
                if (is_link($tmpLink)) {
                    unlink($tmpLink);
                }
                symlink('releases/' . $buildId, $tmpLink);
                if (!rename($tmpLink, $outputDir . '/current')) {
                    throw new RoutirException('Failed to atomically switch current artifact pointer.');
                }
            } finally {
                if (is_dir($stagingDir)) {
                    $this->removeDirectory($stagingDir);
                }
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return [
            'buildId' => $buildId,
            'releaseDir' => $releaseDir,
            'context' => $compiled['context'],
        ];
    }

    private function buildPassManager(CompilerOptions $options): PassManager
    {
        $pm = new PassManager();
        if ($options->optimizationLevel >= 1) {
            $pm->addPass(new StaticDispatchPass());
        }
        if ($options->optimizationLevel >= 2) {
            $pm->addPass(new TrieFactoringPass());
            $pm->addPass(new BlockCleanupPass());
        }

        return $pm;
    }

    /**
     * Lint all generated PHP files in a staging directory using `php -l`.
     *
     * @param string $dir Staging directory path.
     *
     * @return void
     *
     * @throws RoutirException When any generated PHP file fails syntax checking.
     */
    private function lintDirectory(string $dir): void
    {
        $files = glob($dir . '/*.php') ?: [];
        foreach ($files as $file) {
            $out = [];
            $exitCode = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $exitCode);
            if ($exitCode !== 0) {
                throw new RoutirException(sprintf("Generated PHP syntax check failed for %s:\n%s", $file, implode("\n", $out)));
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
