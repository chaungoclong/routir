<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

final readonly class Jump implements LIRInstruction
{
    public function __construct(
        public string $target,
    ) {
    }

    public function targets(): array
    {
        return [$this->target];
    }

    public function isTerminator(): bool
    {
        return true;
    }

    public function dump(): string
    {
        return sprintf('Jump -> %s', $this->target);
    }
}
