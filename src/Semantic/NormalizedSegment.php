<?php

declare(strict_types=1);

namespace Routir\Semantic;

final readonly class NormalizedSegment
{
    public function __construct(
        public string $kind, // 'literal' | 'param' | 'wildcard'
        public string $value, // literal text or parameter name
        public NormalizedConstraint $constraint,
        public bool $optional = false,
    ) {
    }

    public function specificityRank(): int
    {
        return match ($this->kind) {
            'literal' => 4,
            'param' => $this->constraint->isConstrained() ? 3 : 2,
            'wildcard' => 1,
            default => 0,
        };
    }
}
