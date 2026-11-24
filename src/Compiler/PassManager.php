<?php

declare(strict_types=1);

namespace Routir\Compiler;

use Routir\Analysis\IRVerifier;
use Routir\Exception\CompilationException;
use Routir\IR\LIR\LIRModule;

final class PassManager
{
    /** @var list<PassInterface> */
    private array $passes = [];

    public function addPass(PassInterface $pass): void
    {
        $this->passes[] = $pass;
    }

    /**
     * Run all registered optimization passes on an LIR module.
     *
     * @param LIRModule $module Input LIR module.
     * @param CompilationContext $context Compilation context for timings and diagnostics.
     *
     * @return LIRModule
     *
     * @throws CompilationException When IR verification fails before or after a pass.
     */
    public function run(LIRModule $module, CompilationContext $context): LIRModule
    {
        $verifier = new IRVerifier();
        if ($context->options->verifyIR) {
            $verifier->verify($module);
        }

        foreach ($this->passes as $pass) {
            $start = hrtime(true);
            $module = $pass->run($module, $context);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;
            $context->passTimingsMs[$pass->name()] = $elapsedMs;

            if ($context->options->verifyIR) {
                $verifier->verify($module);
            }
        }

        return $module;
    }
}
