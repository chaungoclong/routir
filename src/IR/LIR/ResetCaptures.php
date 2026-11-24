<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

final readonly class ResetCaptures implements LIRInstruction
{
    public function targets(): array
    {
        return [];
    }

    public function isTerminator(): bool
    {
        return false;
    }

    public function dump(): string
    {
        return 'ResetCaptures';
    }
}
