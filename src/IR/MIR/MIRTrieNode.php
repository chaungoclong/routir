<?php

declare(strict_types=1);

namespace Routir\IR\MIR;

final class MIRTrieNode
{
    /**
     * @param list<MIRTrieEdge> $edges
     * @param array<string, string> $terminalDefaults
     */
    public function __construct(
        public readonly int $id,
        public array $edges = [],
        public ?int $terminalRouteId = null,
        public array $terminalDefaults = [],
        public bool $terminalOmittedOptional = false,
        public int $terminalRegistrationOrder = PHP_INT_MAX,
    ) {
    }
}
