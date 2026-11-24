<?php

declare(strict_types=1);

namespace Routir\IR\HIR;

use Routir\Frontend\Handler\HandlerPlan;
use Routir\Semantic\NormalizedSegment;

final readonly class HIRRoute
{
    /**
     * @param list<string> $methods
     * @param list<NormalizedSegment> $segments
     * @param list<string> $middlewarePlan
     * @param array<string, string> $defaults
     */
    public function __construct(
        public int $id,
        public array $methods,
        public string $pattern,
        public array $segments,
        public HandlerPlan $handlerPlan,
        public array $middlewarePlan,
        public ?string $name,
        public array $defaults,
        public int $registrationOrder,
        public ?string $file = null,
        public ?int $line = null,
    ) {
    }
}
