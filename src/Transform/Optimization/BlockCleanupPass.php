<?php

declare(strict_types=1);

namespace Routir\Transform\Optimization;

use Routir\Compiler\CompilationContext;
use Routir\Compiler\PassInterface;
use Routir\IR\LIR\LIRBasicBlock;
use Routir\IR\LIR\LIRFunction;
use Routir\IR\LIR\LIRModule;

/**
 * O2 Pass: Removes unreachable basic blocks from LIR functions.
 */
final class BlockCleanupPass implements PassInterface
{
    public function name(): string
    {
        return 'BlockCleanupPass';
    }

    public function run(LIRModule $ir, CompilationContext $context): LIRModule
    {
        $functions = [];
        foreach ($ir->functions as $method => $fn) {
            $functions[$method] = $this->pruneUnreachable($fn);
        }

        return new LIRModule(
            hir: $ir->hir,
            functions: $functions,
            staticTables: $ir->staticTables,
        );
    }

    private function pruneUnreachable(LIRFunction $fn): LIRFunction
    {
        $visited = [];
        $queue = [$fn->entryLabel];

        while ($queue !== []) {
            $label = array_shift($queue);
            if (isset($visited[$label]) || !isset($fn->blocks[$label])) {
                continue;
            }
            $visited[$label] = true;
            foreach ($fn->blocks[$label]->instructions as $instr) {
                foreach ($instr->targets() as $target) {
                    if (!isset($visited[$target])) {
                        $queue[] = $target;
                    }
                }
            }
        }

        /** @var array<string, LIRBasicBlock> $kept */
        $kept = [];
        foreach ($fn->blocks as $label => $block) {
            if (isset($visited[$label])) {
                $kept[$label] = $block;
            }
        }

        return new LIRFunction($fn->method, $fn->entryLabel, $kept);
    }
}
