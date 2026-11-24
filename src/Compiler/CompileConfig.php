<?php

declare(strict_types=1);

namespace Routir\Compiler;

use Routir\Contracts\RouterMode;
use Routir\Exception\RoutirException;

/**
 * Configuration for route compilation and loading (input paths, output directory, mode, and optimization level).
 */
final readonly class CompileConfig
{
    /** @var list<string> */
    public array $inputs;

    /**
     * Create a new compilation configuration.
     *
     * @param string|list<string> $inputs File(s) or directory(ies) containing route definitions.
     * @param string $outputDir Target directory for compiled artifacts.
     * @param RouterMode $mode Execution mode (Interpreted, Compiled, or Auto).
     * @param int $optimizationLevel Optimization level (0, 1, or 2).
     * @param bool $allowScalarCaptures Whether closures may capture scalar/array values by value.
     */
    public function __construct(
        string|array $inputs,
        public string $outputDir,
        public RouterMode $mode = RouterMode::Auto,
        public int $optimizationLevel = 2,
        public bool $allowScalarCaptures = true,
    ) {
        $this->inputs = is_string($inputs) ? [$inputs] : $inputs;
    }

    /**
     * Build a CompileConfig from an associative configuration array.
     *
     * @param array<string, mixed> $data Configuration array with 'input'/'routes' and 'output'/'artifact'.
     *
     * @return self
     *
     * @throws RoutirException When required configuration keys are missing or have invalid types.
     */
    public static function fromArray(array $data): self
    {
        $rawInput = $data['input'] ?? $data['routes'] ?? null;
        if (is_string($rawInput)) {
            $inputs = [$rawInput];
        } elseif (is_array($rawInput)) {
            $inputs = [];
            foreach ($rawInput as $item) {
                if (!is_string($item) || $item === '') {
                    throw new RoutirException('Each entry in config "input" must be a non-empty string.');
                }
                $inputs[] = $item;
            }
        } else {
            throw new RoutirException('Config must define "input" (or "routes") as a string or list of strings.');
        }

        $output = $data['output'] ?? $data['artifact'] ?? null;
        if (!is_string($output) || $output === '') {
            throw new RoutirException('Config must define "output" (or "artifact") as a non-empty directory path.');
        }

        $rawMode = $data['mode'] ?? RouterMode::Auto;
        if ($rawMode instanceof RouterMode) {
            $mode = $rawMode;
        } elseif (is_string($rawMode)) {
            $mode = RouterMode::from($rawMode);
        } else {
            throw new RoutirException('Config "mode" must be a RouterMode or valid mode string.');
        }

        $rawOpt = $data['optimizationLevel'] ?? 2;
        if (!is_int($rawOpt)) {
            throw new RoutirException('Config "optimizationLevel" must be an integer.');
        }

        $rawScalar = $data['allowScalarCaptures'] ?? true;
        if (!is_bool($rawScalar)) {
            throw new RoutirException('Config "allowScalarCaptures" must be a boolean.');
        }

        return new self(
            inputs: $inputs,
            outputDir: $output,
            mode: $mode,
            optimizationLevel: $rawOpt,
            allowScalarCaptures: $rawScalar,
        );
    }

    /**
     * Load a CompileConfig from a PHP file returning an array or CompileConfig instance.
     *
     * @param string $configFile Path to the PHP configuration file.
     *
     * @return self
     *
     * @throws RoutirException When the file does not exist or returns an invalid configuration.
     */
    public static function fromFile(string $configFile): self
    {
        if (!is_file($configFile)) {
            throw new RoutirException(sprintf('Config file "%s" does not exist.', $configFile));
        }

        $data = require $configFile;
        if ($data instanceof self) {
            return $data;
        }
        if (!is_array($data)) {
            throw new RoutirException(sprintf('Config file "%s" must return an array or CompileConfig.', $configFile));
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }
}
