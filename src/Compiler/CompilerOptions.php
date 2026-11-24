<?php

declare(strict_types=1);

namespace Routir\Compiler;

final readonly class CompilerOptions
{
    public const string SCHEMA_VERSION = '0.1';
    public const string COMPILER_VERSION = '0.1.0';

    public function __construct(
        public int $optimizationLevel = 2,
        public bool $allowScalarCaptures = true,
        public bool $verifyIR = true,
        public bool $lintGeneratedPhp = true,
        public bool $runSmokeTest = true,
    ) {
    }
}
