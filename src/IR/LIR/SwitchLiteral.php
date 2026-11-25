<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

/**
 * LIR terminator instruction for O(1) multi-way literal string dispatch.
 */
final readonly class SwitchLiteral implements LIRInstruction
{
    /**
     * @param string $var Variable name holding the segment value.
     * @param array<string, string> $cases Map of non-numeric literal string => target block label.
     * @param string $default Fallback block label when no case matches.
     */
    public function __construct(
        public string $var,
        public array $cases,
        public string $default,
    ) {
    }

    public function isTerminator(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function targets(): array
    {
        return [...array_values($this->cases), $this->default];
    }

    public function dump(): string
    {
        $pairs = [];
        foreach ($this->cases as $lit => $target) {
            $pairs[] = var_export((string) $lit, true) . ' => ' . $target;
        }

        return sprintf('SwitchLiteral %s { %s, default => %s }', $this->var, implode(', ', $pairs), $this->default);
    }
}
