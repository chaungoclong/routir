<?php

declare(strict_types=1);

namespace Routir\Semantic;

final readonly class NormalizedConstraint
{
    /**
     * @param list<string> $allowedValues
     */
    public function __construct(
        public string $kind, // 'none' | 'builtin' | 'in' | 'regex' | 'wildcard'
        public ?string $builtinName = null,
        public array $allowedValues = [],
        public ?string $compiledRegex = null,
        public ?string $rawRegex = null,
    ) {
    }

    public function signature(): string
    {
        return match ($this->kind) {
            'none' => 'none',
            'wildcard' => 'wildcard',
            'builtin' => 'builtin:' . ($this->builtinName ?? ''),
            'in' => 'in:' . implode(',', $this->allowedValues),
            'regex' => 'regex:' . ($this->rawRegex ?? ''),
            default => $this->kind,
        };
    }

    public function isConstrained(): bool
    {
        return $this->kind !== 'none' && $this->kind !== 'wildcard';
    }
}
