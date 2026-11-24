<?php

declare(strict_types=1);

namespace Routir\IR\MIR;

use Routir\Semantic\NormalizedConstraint;

final readonly class MIRTrieEdge
{
    public function __construct(
        public string $kind, // 'literal' | 'param' | 'wildcard'
        public ?string $literalValue,
        public ?string $paramName,
        public NormalizedConstraint $constraint,
        public MIRTrieNode $target,
        public int $priority,
    ) {
    }
}
