<?php

declare(strict_types=1);

namespace Routir\Contracts;

final readonly class MatchResult
{
    /**
     * @param array<string, string> $params
     * @param list<string> $allowedMethods
     */
    public function __construct(
        public MatchStatus $status,
        public ?int $routeId = null,
        public array $params = [],
        public array $allowedMethods = [],
        public bool $headFallback = false,
        public ?string $errorReason = null,
    ) {
    }
}
