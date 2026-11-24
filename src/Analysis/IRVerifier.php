<?php

declare(strict_types=1);

namespace Routir\Analysis;

use Routir\Diagnostics\Diagnostic;
use Routir\Diagnostics\DiagnosticBag;
use Routir\Diagnostics\Severity;
use Routir\Diagnostics\SourceSpan;
use Routir\Exception\CompilationException;
use Routir\IR\LIR\LIRModule;
use Routir\IR\LIR\ReturnMatch;

final class IRVerifier
{
    /**
     * Verify LIR structural invariants (entry block, terminators, branch targets, and route IDs).
     *
     * @param LIRModule $module LIR module to verify.
     *
     * @return void
     *
     * @throws CompilationException When any IR invariant is violated.
     */
    public function verify(LIRModule $module): void
    {
        $diagnostics = new DiagnosticBag();
        $validRouteIds = [];
        foreach ($module->hir->routes as $route) {
            $validRouteIds[$route->id] = true;
        }

        foreach ($module->functions as $method => $fn) {
            if (!isset($fn->blocks[$fn->entryLabel])) {
                $diagnostics->add(new Diagnostic(
                    code: 'RC_IR_MISSING_ENTRY',
                    severity: Severity::Error,
                    message: sprintf('LIR function for method "%s" is missing entry block "%s".', $method, $fn->entryLabel),
                    span: new SourceSpan(0, 0),
                ));
                continue;
            }

            foreach ($fn->blocks as $label => $block) {
                if ($block->instructions === []) {
                    $diagnostics->add(new Diagnostic(
                        code: 'RC_IR_EMPTY_BLOCK',
                        severity: Severity::Error,
                        message: sprintf('LIR block "%s" in method "%s" is empty.', $label, $method),
                        span: new SourceSpan(0, 0),
                    ));
                    continue;
                }

                $count = count($block->instructions);
                foreach ($block->instructions as $idx => $instr) {
                    $isLast = ($idx === $count - 1);
                    if ($instr->isTerminator() && !$isLast) {
                        $diagnostics->add(new Diagnostic(
                            code: 'RC_IR_MID_TERMINATOR',
                            severity: Severity::Error,
                            message: sprintf('Terminator instruction in block "%s" (%s) is not at the end of the block.', $label, $method),
                            span: new SourceSpan(0, 0),
                        ));
                    }
                    if (!$instr->isTerminator() && $isLast) {
                        $diagnostics->add(new Diagnostic(
                            code: 'RC_IR_NO_TERMINATOR',
                            severity: Severity::Error,
                            message: sprintf('Block "%s" (%s) does not end with a terminator instruction.', $label, $method),
                            span: new SourceSpan(0, 0),
                        ));
                    }

                    foreach ($instr->targets() as $target) {
                        if (!isset($fn->blocks[$target])) {
                            $diagnostics->add(new Diagnostic(
                                code: 'RC_IR_INVALID_TARGET',
                                severity: Severity::Error,
                                message: sprintf('Instruction in block "%s" (%s) jumps to non-existent block "%s".', $label, $method, $target),
                                span: new SourceSpan(0, 0),
                            ));
                        }
                    }

                    if ($instr instanceof ReturnMatch && !isset($validRouteIds[$instr->routeId])) {
                        $diagnostics->add(new Diagnostic(
                            code: 'RC_IR_INVALID_ROUTE_ID',
                            severity: Severity::Error,
                            message: sprintf('ReturnMatch in block "%s" (%s) references invalid route ID %d.', $label, $method, $instr->routeId),
                            span: new SourceSpan(0, 0),
                        ));
                    }
                }
            }
        }

        if ($diagnostics->hasErrors()) {
            throw new CompilationException($diagnostics);
        }
    }
}
