<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

final readonly class BranchEOF implements LIRInstruction
{
    public function __construct(
        public int $segmentCount,
        public string $onEof,
        public string $onMore,
    ) {
    }

    public function targets(): array
    {
        return [$this->onEof, $this->onMore];
    }

    public function isTerminator(): bool
    {
        return true;
    }

    public function dump(): string
    {
        return sprintf('BranchEOF count==%d ? %s : %s', $this->segmentCount, $this->onEof, $this->onMore);
    }
}
