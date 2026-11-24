<?php

declare(strict_types=1);

namespace Routir\Frontend\Handler;

use Closure;

final readonly class HandlerPlan
{
    /**
     * @param list<ParameterPlan> $parameters
     */
    public function __construct(
        public string $kind, // 'closure' | 'controller' | 'invokable' | 'object_method'
        public ?string $className = null,
        public ?string $methodName = null,
        public array $parameters = [],
        public ?Closure $closure = null,
        public ?object $instance = null,
        public ?string $file = null,
        public ?int $line = null,
    ) {
    }
}
