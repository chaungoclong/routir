<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

final readonly class GuardLiteral implements LIRInstruction
{
    public function __construct(
        public string $var,
        public string $expected,
        public string $onMatch,
        public string $onFail,
    ) {
    }

    public function targets(): array
    {
        return [$this->onMatch, $this->onFail];
    }

    public function isTerminator(): bool
    {
        return true;
    }

    public function dump(): string
    {
        return sprintf('GuardLiteral %s == %s ? %s : %s', $this->var, json_encode($this->expected), $this->onMatch, $this->onFail);
    }
}
