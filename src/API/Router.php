<?php

declare(strict_types=1);

namespace Routir\API;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionException;
use Routir\Contracts\MatcherInterface;
use Routir\Contracts\MatchResult;
use Routir\Contracts\ResponseAdapterInterface;
use Routir\Contracts\RouterInterface;
use Routir\Exception\DispatchException;
use Routir\Exception\RouteNotFoundException;
use Routir\Exception\RoutirException;
use Routir\Exception\SemanticException;
use Routir\Exception\UrlGenerationException;
use Routir\Interpreter\InterpretedRuntime;

final class Router implements RouterInterface
{
    /** @var list<string> */
    public const array STANDARD_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    private RouteCollection $collection;
    private bool $frozen = false;
    private int $nextOrder = 0;

    /**
     * @var list<array{
     *   prefix: ?string,
     *   namePrefix: ?string,
     *   middleware: list<string>,
     *   withoutMiddleware: list<string>,
     *   wheres: array<string, string|array{type: 'in', values: list<string>}>,
     *   defaults: array<string, string>
     * }>
     */
    private array $groupStack = [];

    private ?InterpretedRuntime $runtime = null;

    public function __construct(
        private readonly ?ContainerInterface $container = null,
        private readonly ?ResponseAdapterInterface $responseAdapter = null,
        private readonly ?ResponseFactoryInterface $responseFactory = null,
        private readonly ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->collection = new RouteCollection();
    }

    public function get(string $pattern, mixed $handler): RouteBuilder
    {
        return $this->addRoute(['GET'], $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): RouteBuilder
    {
        return $this->addRoute(['POST'], $pattern, $handler);
    }

    public function put(string $pattern, mixed $handler): RouteBuilder
    {
        return $this->addRoute(['PUT'], $pattern, $handler);
    }

    public function patch(string $pattern, mixed $handler): RouteBuilder
    {
        return $this->addRoute(['PATCH'], $pattern, $handler);
    }

    public function delete(string $pattern, mixed $handler): RouteBuilder
    {
        return $this->addRoute(['DELETE'], $pattern, $handler);
    }

    public function options(string $pattern, mixed $handler): RouteBuilder
    {
        return $this->addRoute(['OPTIONS'], $pattern, $handler);
    }

    public function head(string $pattern, mixed $handler): RouteBuilder
    {
        return $this->addRoute(['HEAD'], $pattern, $handler);
    }

    /**
     * @param list<string> $methods
     */
    public function match(array $methods, string $pattern, mixed $handler): RouteBuilder
    {
        return $this->addRoute($methods, $pattern, $handler);
    }

    public function any(string $pattern, mixed $handler): RouteBuilder
    {
        return $this->addRoute(self::STANDARD_METHODS, $pattern, $handler);
    }

    public function prefix(string $prefix): GroupBuilder
    {
        $this->assertNotFrozen();

        return new GroupBuilder($this)->prefix($prefix);
    }

    public function name(string $namePrefix): GroupBuilder
    {
        $this->assertNotFrozen();

        return new GroupBuilder($this)->name($namePrefix);
    }

    /**
     * @param string|list<string> $middleware
     */
    public function middleware(string|array $middleware): GroupBuilder
    {
        $this->assertNotFrozen();

        return new GroupBuilder($this)->middleware($middleware);
    }

    /**
     * @param string|list<string> $middleware
     */
    public function withoutMiddleware(string|array $middleware): GroupBuilder
    {
        $this->assertNotFrozen();

        return new GroupBuilder($this)->withoutMiddleware($middleware);
    }

    /**
     * @param string|array<string, string> $param
     */
    public function where(string|array $param, ?string $regex = null): GroupBuilder
    {
        $this->assertNotFrozen();

        return new GroupBuilder($this)->where($param, $regex);
    }

    /**
     * @param string|list<string> $params
     */
    public function whereNumber(string|array $params): GroupBuilder
    {
        $this->assertNotFrozen();

        return new GroupBuilder($this)->whereNumber($params);
    }

    /**
     * @param string|list<string> $params
     */
    public function whereAlpha(string|array $params): GroupBuilder
    {
        $this->assertNotFrozen();

        return new GroupBuilder($this)->whereAlpha($params);
    }

    /**
     * @param string|list<string> $params
     */
    public function whereUuid(string|array $params): GroupBuilder
    {
        $this->assertNotFrozen();

        return new GroupBuilder($this)->whereUuid($params);
    }

    /**
     * @param list<string> $values
     */
    public function whereIn(string $param, array $values): GroupBuilder
    {
        $this->assertNotFrozen();

        return new GroupBuilder($this)->whereIn($param, $values);
    }

    /**
     * @param string|array<string, string> $param
     */
    public function defaults(string|array $param, ?string $value = null): GroupBuilder
    {
        $this->assertNotFrozen();

        return new GroupBuilder($this)->defaults($param, $value);
    }

    public function group(callable $callback): void
    {
        $this->assertNotFrozen();
        $this->executeGroup([
            'prefix' => null,
            'namePrefix' => null,
            'middleware' => [],
            'withoutMiddleware' => [],
            'wheres' => [],
            'defaults' => [],
        ], $callback);
    }

    public function fallback(mixed $handler): void
    {
        $this->assertNotFrozen();
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        $file = $trace[0]['file'] ?? null;
        $line = $trace[0]['line'] ?? null;
        $this->collection->setFallback($handler, $file, $line);
    }

    /**
     * @param string|list<string> $target
     */
    public function middlewareAlias(string $alias, string|array $target): self
    {
        $this->assertNotFrozen();
        $this->collection->registerMiddlewareAlias($alias, $target);

        return $this;
    }

    /**
     * @param list<string> $orderedMiddleware
     */
    public function middlewarePriority(array $orderedMiddleware): self
    {
        $this->assertNotFrozen();
        $this->collection->setMiddlewarePriority($orderedMiddleware);

        return $this;
    }

    /**
     * @internal Used by GroupBuilder
     * @param array{
     *   prefix: ?string,
     *   namePrefix: ?string,
     *   middleware: list<string>,
     *   withoutMiddleware: list<string>,
     *   wheres: array<string, string|array{type: 'in', values: list<string>}>,
     *   defaults: array<string, string>
     * } $attributes
     */
    public function executeGroup(array $attributes, callable $callback): void
    {
        $this->assertNotFrozen();
        $this->groupStack[] = $attributes;
        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /** @return list<RouteDefinition> */
    public function getRoutes(): array
    {
        return $this->collection->all();
    }

    public function getByName(string $name): ?RouteDefinition
    {
        return $this->collection->getByName($name);
    }

    public function has(string $name): bool
    {
        return $this->collection->has($name);
    }

    public function getCollection(): RouteCollection
    {
        return $this->collection;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Get the underlying reference matcher, freezing and booting the runtime if needed.
     *
     * @return MatcherInterface
     *
     * @throws SemanticException When route or handler semantic analysis fails.
     * @throws ReflectionException When handler reflection fails.
     */
    public function matcher(): MatcherInterface
    {
        return $this->bootRuntime()->matcher();
    }

    /**
     * Match an HTTP method and request target against registered routes.
     *
     * @param string $method HTTP method.
     * @param string $requestTarget Raw request target URI.
     *
     * @return MatchResult
     *
     * @throws SemanticException When route or handler semantic analysis fails.
     * @throws ReflectionException When handler reflection fails.
     */
    public function matchRequest(string $method, string $requestTarget): MatchResult
    {
        return $this->bootRuntime()->match($method, $requestTarget);
    }

    /**
     * Dispatch an incoming PSR-7 request.
     *
     * @param ServerRequestInterface $request Incoming HTTP request.
     *
     * @return ResponseInterface
     *
     * @throws SemanticException When route or handler semantic analysis fails.
     * @throws DispatchException When middleware or handler invocation fails.
     * @throws ReflectionException When handler or service reflection fails.
     * @throws ContainerExceptionInterface When container resolution fails.
     * @throws NotFoundExceptionInterface When a container service is missing.
     */
    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        return $this->bootRuntime()->dispatch($request);
    }

    /**
     * Generate a relative URL for a named route.
     *
     * @param string $name Route name.
     * @param array<string, mixed> $params Route and query parameters.
     *
     * @return string
     *
     * @throws SemanticException When route or handler semantic analysis fails.
     * @throws ReflectionException When handler reflection fails.
     * @throws RouteNotFoundException When the named route does not exist.
     * @throws UrlGenerationException When parameters fail validation or required parameters are missing.
     * @throws DispatchException When a custom regex constraint fails.
     */
    public function route(string $name, array $params = []): string
    {
        return $this->bootRuntime()->route($name, $params);
    }

    /**
     * Freeze registration and boot the interpreted runtime once.
     *
     * @return InterpretedRuntime
     *
     * @throws SemanticException When route or handler semantic analysis fails.
     * @throws ReflectionException When handler reflection fails.
     */
    private function bootRuntime(): InterpretedRuntime
    {
        if ($this->runtime === null) {
            $this->freeze();
            $this->runtime = InterpretedRuntime::fromCollection(
                collection: $this->collection,
                container: $this->container,
                responseAdapter: $this->responseAdapter,
                responseFactory: $this->responseFactory,
                streamFactory: $this->streamFactory,
            );
        }

        return $this->runtime;
    }

    /**
     * Register a route for one or more HTTP methods.
     *
     * @param list<string> $methods Uppercase HTTP method tokens.
     * @param string $pattern Route pattern.
     * @param mixed $handler Route handler.
     *
     * @return RouteBuilder
     *
     * @throws RoutirException When the router is frozen or HTTP methods are invalid.
     */
    private function addRoute(array $methods, string $pattern, mixed $handler): RouteBuilder
    {
        $this->assertNotFrozen();

        if ($methods === []) {
            throw new RoutirException('Route must specify at least one HTTP method.');
        }

        foreach ($methods as $m) {
            if ($m === '' || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Z]+$/', $m) !== 1) {
                throw new RoutirException(sprintf('Invalid HTTP method token "%s"; registration requires uppercase valid method tokens.', $m));
            }
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[1] ?? $trace[0] ?? [];
        $file = $caller['file'] ?? null;
        $line = $caller['line'] ?? null;

        $prefixParts = [];
        $namePrefix = '';
        $middleware = [];
        $withoutMiddleware = [];
        $wheres = [];
        $defaults = [];

        foreach ($this->groupStack as $group) {
            if ($group['prefix'] !== null && $group['prefix'] !== '') {
                $prefixParts[] = $group['prefix'];
            }
            if ($group['namePrefix'] !== null) {
                $namePrefix .= $group['namePrefix'];
            }
            foreach ($group['middleware'] as $mw) {
                $middleware[] = $mw;
            }
            foreach ($group['withoutMiddleware'] as $wmw) {
                $withoutMiddleware[] = $wmw;
            }
            foreach ($group['wheres'] as $k => $v) {
                $wheres[$k] = $v;
            }
            foreach ($group['defaults'] as $k => $v) {
                $defaults[$k] = $v;
            }
        }

        $fullPattern = $this->concatenatePrefixAndPattern($prefixParts, $pattern);

        $definition = new RouteDefinition(
            registrationOrder: $this->nextOrder++,
            methods: array_values(array_unique($methods)),
            pattern: $fullPattern,
            handler: $handler,
            namePrefix: $namePrefix !== '' ? $namePrefix : null,
            middleware: $middleware,
            withoutMiddleware: $withoutMiddleware,
            wheres: $wheres,
            defaults: $defaults,
            file: $file,
            line: $line,
        );

        $this->collection->add($definition);

        return new RouteBuilder($definition, $this->assertNotFrozen(...));
    }

    /**
     * @param list<string> $prefixes
     */
    private function concatenatePrefixAndPattern(array $prefixes, string $pattern): string
    {
        $segments = [];
        foreach ($prefixes as $prefix) {
            $trimmed = trim($prefix, '/');
            if ($trimmed !== '') {
                $segments[] = $trimmed;
            }
        }

        if ($pattern === '') {
            return '';
        }

        if ($pattern[0] !== '/') {
            // Preserve invalid leading slash so parser catches it with proper diagnostic
            return $pattern;
        }

        $trimmedPattern = trim($pattern, '/');
        if ($trimmedPattern !== '') {
            $segments[] = $trimmedPattern;
        } elseif ($pattern !== '/' && strlen($pattern) > 1) {
            // Preserve malformed patterns like '//' or '/users/'
            return $pattern;
        }

        if ($segments === []) {
            return '/';
        }

        $joined = '/' . implode('/', $segments);

        // If the original pattern had a trailing slash on non-root, preserve it for parser rejection
        if ($pattern !== '/' && str_ends_with($pattern, '/')) {
            $joined .= '/';
        }

        return $joined;
    }

    /**
     * Ensure routes have not been frozen before mutating definitions.
     *
     * @return void
     *
     * @throws RoutirException When the router has already been frozen.
     */
    private function assertNotFrozen(): void
    {
        if ($this->frozen) {
            throw new RoutirException('Cannot modify routes after the router has been frozen.');
        }
    }
}
