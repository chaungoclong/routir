<?php

declare(strict_types=1);

namespace Routir\API;

use Closure;

final class RouteBuilder
{
    public function __construct(
        private readonly RouteDefinition $definition,
        private readonly Closure $assertNotFrozen,
    ) {
    }

    public function name(string $name): self
    {
        ($this->assertNotFrozen)();
        $this->definition->name = ($this->definition->namePrefix ?? '') . $name;

        return $this;
    }

    /**
     * @param string|list<string> $middleware
     */
    public function middleware(string|array $middleware): self
    {
        ($this->assertNotFrozen)();
        $list = is_array($middleware) ? $middleware : [$middleware];
        foreach ($list as $m) {
            $this->definition->middleware[] = $m;
        }

        return $this;
    }

    /**
     * @param string|list<string> $middleware
     */
    public function withoutMiddleware(string|array $middleware): self
    {
        ($this->assertNotFrozen)();
        $list = is_array($middleware) ? $middleware : [$middleware];
        foreach ($list as $m) {
            $this->definition->withoutMiddleware[] = $m;
        }

        return $this;
    }

    /**
     * @param string|array<string, string> $param
     */
    public function where(string|array $param, ?string $regex = null): self
    {
        ($this->assertNotFrozen)();
        if (is_array($param)) {
            foreach ($param as $k => $v) {
                $this->definition->wheres[$k] = $v;
            }
        } elseif ($regex !== null) {
            $this->definition->wheres[$param] = $regex;
        }

        return $this;
    }

    /**
     * @param string|list<string> $params
     */
    public function whereNumber(string|array $params): self
    {
        ($this->assertNotFrozen)();
        foreach ((array) $params as $param) {
            $this->definition->wheres[$param] = '__builtin:int';
        }

        return $this;
    }

    /**
     * @param string|list<string> $params
     */
    public function whereAlpha(string|array $params): self
    {
        ($this->assertNotFrozen)();
        foreach ((array) $params as $param) {
            $this->definition->wheres[$param] = '__builtin:alpha';
        }

        return $this;
    }

    /**
     * @param string|list<string> $params
     */
    public function whereUuid(string|array $params): self
    {
        ($this->assertNotFrozen)();
        foreach ((array) $params as $param) {
            $this->definition->wheres[$param] = '__builtin:uuid';
        }

        return $this;
    }

    /**
     * @param list<string> $values
     */
    public function whereIn(string $param, array $values): self
    {
        ($this->assertNotFrozen)();
        $this->definition->wheres[$param] = [
            'type' => 'in',
            'values' => $values,
        ];

        return $this;
    }

    /**
     * @param string|array<string, string> $param
     */
    public function defaults(string|array $param, ?string $value = null): self
    {
        ($this->assertNotFrozen)();
        if (is_array($param)) {
            foreach ($param as $k => $v) {
                $this->definition->defaults[$k] = (string) $v;
            }
        } elseif ($value !== null) {
            $this->definition->defaults[$param] = $value;
        }

        return $this;
    }

    public function bind(string $param, mixed $value): self
    {
        ($this->assertNotFrozen)();
        $this->definition->bindings[$param] = $value;

        return $this;
    }
}
