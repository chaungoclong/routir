<?php

declare(strict_types=1);

namespace Routir\API;

final class RouteDefinition
{
    /** @var list<string> */
    public array $methods;
    public string $pattern;
    public mixed $handler;
    public ?string $name = null;
    /** @var list<string> */
    public array $middleware = [];
    /** @var list<string> */
    public array $withoutMiddleware = [];
    /** @var array<string, string|array{type: 'in', values: list<string>}> */
    public array $wheres = [];
    /** @var array<string, string> */
    public array $defaults = [];
    /** @var array<string, mixed> */
    public array $bindings = [];

    /**
     * @param list<string> $methods
     * @param list<string> $middleware
     * @param list<string> $withoutMiddleware
     * @param array<string, string|array{type: 'in', values: list<string>}> $wheres
     * @param array<string, string> $defaults
     */
    public function __construct(
        public readonly int $registrationOrder,
        array $methods,
        string $pattern,
        mixed $handler,
        ?string $namePrefix = null,
        array $middleware = [],
        array $withoutMiddleware = [],
        array $wheres = [],
        array $defaults = [],
        public readonly ?string $file = null,
        public readonly ?int $line = null,
    ) {
        $this->methods = $methods;
        $this->pattern = $pattern;
        $this->handler = $handler;
        $this->namePrefix = $namePrefix;
        $this->middleware = $middleware;
        $this->withoutMiddleware = $withoutMiddleware;
        $this->wheres = $wheres;
        $this->defaults = $defaults;
    }

    public ?string $namePrefix = null;
}
