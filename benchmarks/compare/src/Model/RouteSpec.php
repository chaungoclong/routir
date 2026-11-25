<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Model;

/**
 * Immutable route specification shared across all benchmark router adapters.
 */
final readonly class RouteSpec
{
    /**
     * @param RouteType $type Route shape category.
     * @param string $routirPath Route pattern using Routir DSL syntax.
     * @param string $standardPath Route pattern using standard `{param}` placeholders.
     * @param array<string, string> $wheres Regex constraints keyed by parameter name.
     * @param string $name Unique route name.
     */
    public function __construct(
        public RouteType $type,
        public string $routirPath,
        public string $standardPath,
        public array $wheres,
        public string $name,
    ) {
    }

    /**
     * Build a FastRoute-compatible pattern with inline `{param:regex}` constraints.
     *
     * @return string
     */
    public function toFastRoutePattern(): string
    {
        $pattern = $this->standardPath;
        foreach ($this->wheres as $param => $regex) {
            $pattern = str_replace('{' . $param . '}', '{' . $param . ':' . $regex . '}', $pattern);
        }

        return $pattern;
    }
}
