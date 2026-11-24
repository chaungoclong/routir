<?php

declare(strict_types=1);

namespace Routir\Semantic;

final readonly class NormalizedRoute
{
    /**
     * @param list<string> $methods
     * @param list<NormalizedSegment> $segments
     * @param list<string> $middleware
     * @param array<string, string> $defaults
     * @param array<string, mixed> $bindings
     */
    public function __construct(
        public int $id,
        public int $registrationOrder,
        public array $methods,
        public string $pattern,
        public array $segments,
        public ?string $name,
        public mixed $handler,
        public array $middleware,
        public array $defaults,
        public array $bindings,
        public string $signature,
        public ?string $file = null,
        public ?int $line = null,
    ) {
    }

    public function isStatic(): bool
    {
        foreach ($this->segments as $seg) {
            if ($seg->kind !== 'literal') {
                return false;
            }
        }

        return true;
    }

    public function hasOptional(): bool
    {
        foreach ($this->segments as $seg) {
            if ($seg->optional) {
                return true;
            }
        }

        return false;
    }

    public function hasWildcard(): bool
    {
        $count = count($this->segments);

        return $count > 0 && $this->segments[$count - 1]->kind === 'wildcard';
    }

    public function requiredSegmentCount(): int
    {
        $count = 0;
        foreach ($this->segments as $seg) {
            if (!$seg->optional) {
                $count++;
            }
        }

        return $count;
    }
}
