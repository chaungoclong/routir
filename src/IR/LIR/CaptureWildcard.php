<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

final readonly class CaptureWildcard implements LIRInstruction
{
    public function __construct(
        public string $paramName,
        public int $startIndex,
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
        return sprintf('CaptureWildcard %s <- segments[%d..]', $this->paramName, $this->startIndex);
    }
}
