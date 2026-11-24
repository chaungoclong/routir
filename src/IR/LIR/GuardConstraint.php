<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

use Routir\Semantic\NormalizedConstraint;

final readonly class GuardConstraint implements LIRInstruction
{
    public function __construct(
        public string $var,
        public NormalizedConstraint $constraint,
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
        return sprintf('GuardConstraint %s : %s ? %s : %s', $this->var, $this->constraint->signature(), $this->onMatch, $this->onFail);
    }
}
