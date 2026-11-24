<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

final class LIRFunction
{
    /**
     * @param array<string, LIRBasicBlock> $blocks
     */
    public function __construct(
        public readonly string $method,
        public string $entryLabel,
        public array $blocks = [],
    ) {
    }

    public function dump(): string
    {
        $out = sprintf("fn match_%s(segments) entry=%s {\n", $this->method, $this->entryLabel);
        foreach ($this->blocks as $block) {
            $out .= "  {$block->label}:\n";
            foreach ($block->instructions as $instr) {
                $out .= "    " . $instr->dump() . "\n";
            }
        }
        $out .= "}\n";

        return $out;
    }
}
