<?php

declare(strict_types=1);

namespace Routir\IR\HIR;

use Routir\Frontend\Handler\HandlerPlan;

final readonly class HIRModule
{
    /**
     * @param list<HIRRoute> $routes
     * @param array<string, HIRRoute> $namedRoutes
     */
    public function __construct(
        public array $routes,
        public array $namedRoutes,
        public ?HandlerPlan $fallbackPlan = null,
    ) {
    }
}
