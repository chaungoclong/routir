<?php

declare(strict_types=1);

namespace Routir\IR\MIR;

use Routir\IR\HIR\HIRModule;
use Routir\IR\HIR\HIRRoute;

final readonly class MIRModule
{
    /**
     * @param array<string, list<MIRCandidate>> $methodCandidates
     * @param array<string, MIRTrieNode> $methodTries
     * @param array<int, HIRRoute> $routesById
     */
    public function __construct(
        public HIRModule $hir,
        public array $methodCandidates,
        public array $methodTries,
        public array $routesById,
    ) {
    }
}
