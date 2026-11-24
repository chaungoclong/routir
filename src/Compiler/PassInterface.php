<?php

declare(strict_types=1);

namespace Routir\Compiler;

use Routir\IR\LIR\LIRModule;

interface PassInterface
{
    public function name(): string;

    public function run(LIRModule $ir, CompilationContext $context): LIRModule;
}
