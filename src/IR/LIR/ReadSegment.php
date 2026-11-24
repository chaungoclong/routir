<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

final readonly class ReadSegment implements LIRInstruction
{
    public function __construct(
        public int $index,
        public string $targetVar,
        public string $onPresent,
        public string $onMissing,
    ) {
    }

    public function targets(): array
    {
        return [$this->onPresent, $this->onMissing];
    }

    public function isTerminator(): bool
    {
        return true;
    }

    public function dump(): string
    {
        return sprintf('ReadSegment idx=%d -> %s ? %s : %s', $this->index, $this->targetVar, $this->onPresent, $this->onMissing);
    }
}
