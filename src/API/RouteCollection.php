<?php

declare(strict_types=1);

namespace Routir\API;

final class RouteCollection
{
    /** @var list<RouteDefinition> */
    private array $routes = [];
    private mixed $fallbackHandler = null;
    private ?string $fallbackFile = null;
    private ?int $fallbackLine = null;
    /** @var array<string, string|list<string>> */
    private array $middlewareAliases = [];
    /** @var array<string, int> */
    private array $middlewarePriorities = [];

    public function add(RouteDefinition $route): void
    {
        $this->routes[] = $route;
    }

    /** @return list<RouteDefinition> */
    public function all(): array
    {
        return $this->routes;
    }

    /**
     * Find a registered route definition by its unique name.
     *
     * @param string $name Route name.
     *
     * @return ?RouteDefinition Matching definition or null if not found.
     */
    public function getByName(string $name): ?RouteDefinition
    {
        return array_find(
            $this->routes,
            static fn (RouteDefinition $route): bool => $route->name === $name,
        );
    }

    public function has(string $name): bool
    {
        return $this->getByName($name) !== null;
    }

    public function setFallback(mixed $handler, ?string $file = null, ?int $line = null): void
    {
        $this->fallbackHandler = $handler;
        $this->fallbackFile = $file;
        $this->fallbackLine = $line;
    }

    public function getFallbackHandler(): mixed
    {
        return $this->fallbackHandler;
    }

    public function getFallbackFile(): ?string
    {
        return $this->fallbackFile;
    }

    public function getFallbackLine(): ?int
    {
        return $this->fallbackLine;
    }

    /**
     * @param string|list<string> $target
     */
    public function registerMiddlewareAlias(string $alias, string|array $target): void
    {
        $this->middlewareAliases[$alias] = $target;
    }

    /** @return array<string, string|list<string>> */
    public function getMiddlewareAliases(): array
    {
        return $this->middlewareAliases;
    }

    /**
     * @param list<string> $orderedMiddleware
     */
    public function setMiddlewarePriority(array $orderedMiddleware): void
    {
        $this->middlewarePriorities = [];
        foreach ($orderedMiddleware as $idx => $mw) {
            $this->middlewarePriorities[$mw] = $idx;
        }
    }

    /** @return array<string, int> */
    public function getMiddlewarePriorities(): array
    {
        return $this->middlewarePriorities;
    }
}
