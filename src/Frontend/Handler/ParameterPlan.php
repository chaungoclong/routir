<?php

declare(strict_types=1);

namespace Routir\Frontend\Handler;

final readonly class ParameterPlan
{
    public function __construct(
        public string $name,
        public string $source, // 'binding' | 'route_param' | 'request' | 'service' | 'default'
        public ?string $typeName = null,
        public bool $allowsNull = false,
        public bool $hasDefault = false,
        public mixed $defaultValue = null,
        public mixed $bindingValue = null,
    ) {
    }
}
