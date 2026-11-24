<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

final readonly class Capture implements LIRInstruction
{
    public function __construct(
        public string $paramName,
        public string $sourceVar,
    ) {
    }

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
        return sprintf('Capture %s <- %s', $this->paramName, $this->sourceVar);
    }
}
