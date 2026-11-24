<?php

declare(strict_types=1);

namespace Routir\Semantic;

use Routir\Diagnostics\DiagnosticBag;

final readonly class SemanticResult
{
    /**
     * @param list<NormalizedRoute> $routes
     * @param array<string, NormalizedRoute> $namedRoutes
     */
    public function __construct(
        public array $routes,
        public array $namedRoutes,
        public mixed $fallbackHandler,
        public ?string $fallbackFile,
        public ?int $fallbackLine,
        public DiagnosticBag $diagnostics,
    ) {
    }
}
