<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

interface LIRInstruction
{
    /** @return list<string> */
    public function targets(): array;

    public function isTerminator(): bool;

    public function dump(): string;
}
