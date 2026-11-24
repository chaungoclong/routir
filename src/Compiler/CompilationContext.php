<?php

declare(strict_types=1);

namespace Routir\Compiler;

use Routir\Diagnostics\DiagnosticBag;

final class CompilationContext
{
    /** @var array<string, float> */
    public array $passTimingsMs = [];

    public function __construct(
        public readonly CompilerOptions $options,
        public readonly DiagnosticBag $diagnostics = new DiagnosticBag(),
    ) {
    }
}
