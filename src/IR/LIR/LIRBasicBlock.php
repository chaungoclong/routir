<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

final class LIRBasicBlock
{
    /**
     * @param list<LIRInstruction> $instructions
     */
    public function __construct(
        public readonly string $label,
        public array $instructions = [],
    ) {
    }
}
