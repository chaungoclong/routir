<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

final readonly class ReturnMiss implements LIRInstruction
{
    public function targets(): array
    {
        return [];
    }

    public function isTerminator(): bool
    {
        return true;
    }

    public function dump(): string
    {
        return 'ReturnMiss';
    }
}
