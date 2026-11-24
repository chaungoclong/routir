<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

final readonly class ReturnMatch implements LIRInstruction
{
    /**
     * @param array<string, string> $defaults
     */
    public function __construct(
        public int $routeId,
        public array $defaults = [],
    ) {
    }

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
        return sprintf('ReturnMatch route=%d defaults=%s', $this->routeId, json_encode($this->defaults));
    }
}
