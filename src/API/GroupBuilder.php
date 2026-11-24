<?php

declare(strict_types=1);

namespace Routir\API;

final class GroupBuilder
{
    private ?string $prefix = null;
    private ?string $namePrefix = null;
    /** @var list<string> */
    private array $middleware = [];
    /** @var list<string> */
    private array $withoutMiddleware = [];
    /** @var array<string, string|array{type: 'in', values: list<string>}> */
    private array $wheres = [];
    /** @var array<string, string> */
    private array $defaults = [];

    public function __construct(
        private readonly Router $router,
    ) {
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function name(string $namePrefix): self
    {
        $this->namePrefix = $namePrefix;

        return $this;
    }

    /**
     * @param string|list<string> $middleware
     */
    public function middleware(string|array $middleware): self
    {
        $list = is_array($middleware) ? $middleware : [$middleware];
        foreach ($list as $m) {
            $this->middleware[] = $m;
        }

        return $this;
    }

    /**
     * @param string|list<string> $middleware
     */
    public function withoutMiddleware(string|array $middleware): self
    {
        $list = is_array($middleware) ? $middleware : [$middleware];
        foreach ($list as $m) {
            $this->withoutMiddleware[] = $m;
        }

        return $this;
    }

    /**
     * @param string|array<string, string> $param
     */
    public function where(string|array $param, ?string $regex = null): self
    {
        if (is_array($param)) {
            foreach ($param as $k => $v) {
                $this->wheres[$k] = $v;
            }
        } elseif ($regex !== null) {
            $this->wheres[$param] = $regex;
        }

        return $this;
    }

    /**
     * @param string|list<string> $params
     */
    public function whereNumber(string|array $params): self
    {
        foreach ((array) $params as $param) {
            $this->wheres[$param] = '__builtin:int';
        }

        return $this;
    }

    /**
     * @param string|list<string> $params
     */
    public function whereAlpha(string|array $params): self
    {
        foreach ((array) $params as $param) {
            $this->wheres[$param] = '__builtin:alpha';
        }

        return $this;
    }

    /**
     * @param string|list<string> $params
     */
    public function whereUuid(string|array $params): self
    {
        foreach ((array) $params as $param) {
            $this->wheres[$param] = '__builtin:uuid';
        }

        return $this;
    }

    /**
     * @param list<string> $values
     */
    public function whereIn(string $param, array $values): self
    {
        $this->wheres[$param] = [
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
        if (is_array($param)) {
            foreach ($param as $k => $v) {
                $this->defaults[$k] = (string) $v;
            }
        } elseif ($value !== null) {
            $this->defaults[$param] = $value;
        }

        return $this;
    }

    public function group(callable $callback): void
    {
        $this->router->executeGroup([
            'prefix' => $this->prefix,
            'namePrefix' => $this->namePrefix,
            'middleware' => $this->middleware,
            'withoutMiddleware' => $this->withoutMiddleware,
            'wheres' => $this->wheres,
            'defaults' => $this->defaults,
        ], $callback);
    }
}
