<?php

declare(strict_types=1);

namespace Routir\Interpreter;

use Closure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionException;
use Routir\API\RouteCollection;
use Routir\Contracts\MatcherInterface;
use Routir\Contracts\MatchResult;
use Routir\Contracts\MatchStatus;
use Routir\Contracts\MiddlewareFactoryInterface;
use Routir\Contracts\ResponseAdapterInterface;
use Routir\Exception\DispatchException;
use Routir\Exception\RouteNotFoundException;
use Routir\Exception\SemanticException;
use Routir\Exception\UrlGenerationException;
use Routir\Frontend\Handler\HandlerAnalyzer;
use Routir\Frontend\Handler\HandlerPlan;
use Routir\Middleware\MiddlewarePipeline;
use Routir\Runtime\DefaultResponseAdapter;
use Routir\Runtime\MinimalPsr17Factory;
use Routir\Runtime\ParameterConverter;
use Routir\Runtime\UrlGenerator;
use Routir\Semantic\NormalizedRoute;
use Routir\Semantic\SemanticAnalyzer;
use Routir\Semantic\SemanticResult;

final class InterpretedRuntime
{
    /** @var array<int, NormalizedRoute> */
    private array $routesById = [];
    /** @var array<int, HandlerPlan> */
    private array $handlerPlans = [];
    private ?HandlerPlan $fallbackPlan = null;

    /**
     * Construct an interpreted runtime from validated semantic routes.
     *
     * @param SemanticResult $semantic Validated semantic analysis result.
     * @param ReferenceMatcher $matcher Reference matcher.
     * @param UrlGenerator $urlGenerator Reverse URL generator.
     * @param ?ContainerInterface $container Optional PSR-11 container.
     * @param ResponseAdapterInterface $responseAdapter Response adapter.
     * @param ResponseFactoryInterface $responseFactory PSR-17 response factory.
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory.
     * @param ?MiddlewareFactoryInterface $middlewareFactory Optional parameterized middleware factory.
     *
     * @throws SemanticException When handler or parameter analysis fails.
     * @throws ReflectionException When handler reflection fails.
     */
    public function __construct(
        private readonly SemanticResult $semantic,
        private readonly ReferenceMatcher $matcher,
        private readonly UrlGenerator $urlGenerator,
        private readonly ?ContainerInterface $container,
        private readonly ResponseAdapterInterface $responseAdapter,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?MiddlewareFactoryInterface $middlewareFactory = null,
    ) {
        $analyzer = new HandlerAnalyzer();
        foreach ($semantic->routes as $route) {
            $this->routesById[$route->id] = $route;
            $this->handlerPlans[$route->id] = $analyzer->analyze($route);
        }

        if ($semantic->fallbackHandler !== null) {
            $dummyRoute = new NormalizedRoute(
                id: -1,
                registrationOrder: -1,
                methods: ['GET'],
                pattern: '/',
                segments: [],
                name: null,
                handler: $semantic->fallbackHandler,
                middleware: [],
                defaults: [],
                bindings: [],
                signature: '/',
                file: $semantic->fallbackFile,
                line: $semantic->fallbackLine,
            );
            $this->fallbackPlan = $analyzer->analyze($dummyRoute);
        }
    }

    /**
     * Build an InterpretedRuntime directly from a RouteCollection.
     *
     * @param RouteCollection $collection Collected route definitions.
     * @param ?ContainerInterface $container Optional PSR-11 container.
     * @param ?ResponseAdapterInterface $responseAdapter Optional response adapter.
     * @param ?ResponseFactoryInterface $responseFactory Optional PSR-17 response factory.
     * @param ?StreamFactoryInterface $streamFactory Optional PSR-17 stream factory.
     * @param ?MiddlewareFactoryInterface $middlewareFactory Optional parameterized middleware factory.
     *
     * @return self
     *
     * @throws SemanticException When semantic or handler analysis fails.
     * @throws ReflectionException When handler reflection fails.
     */
    public static function fromCollection(
        RouteCollection $collection,
        ?ContainerInterface $container = null,
        ?ResponseAdapterInterface $responseAdapter = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?MiddlewareFactoryInterface $middlewareFactory = null,
    ): self {
        $semantic = new SemanticAnalyzer()->analyze($collection);
        $matcher = new ReferenceMatcher($semantic);
        $urlGenerator = new UrlGenerator($semantic->namedRoutes);
        $psr17 = new MinimalPsr17Factory();

        return new self(
            semantic: $semantic,
            matcher: $matcher,
            urlGenerator: $urlGenerator,
            container: $container,
            responseAdapter: $responseAdapter ?? new DefaultResponseAdapter($psr17),
            responseFactory: $responseFactory ?? $psr17,
            streamFactory: $streamFactory ?? $psr17,
            middlewareFactory: $middlewareFactory,
        );
    }

    public function matcher(): MatcherInterface
    {
        return $this->matcher;
    }

    public function match(string $method, string $requestTarget): MatchResult
    {
        return $this->matcher->match($method, $requestTarget);
    }

    /**
     * Generate a relative URL path for a named route.
     *
     * @param string $name Route name.
     * @param array<string, mixed> $params Route and query parameters.
     *
     * @return string
     *
     * @throws RouteNotFoundException When the route name is not registered.
     * @throws UrlGenerationException When parameters fail validation or required parameters are missing.
     * @throws DispatchException When a custom regex constraint fails.
     */
    public function route(string $name, array $params = []): string
    {
        return $this->urlGenerator->generate($name, $params);
    }

    public function has(string $name): bool
    {
        return $this->urlGenerator->has($name);
    }

    /**
     * Dispatch an incoming PSR-7 ServerRequestInterface to the matched route or error response.
     *
     * @param ServerRequestInterface $request Incoming HTTP request.
     *
     * @return ResponseInterface
     *
     * @throws DispatchException When handler invocation, parameter conversion, or middleware resolution fails.
     * @throws ReflectionException When service class reflection fails.
     * @throws ContainerExceptionInterface When container resolution fails.
     * @throws NotFoundExceptionInterface When a container service is missing.
     */
    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->matcher->match($request->getMethod(), $request->getRequestTarget());

        return match ($result->status) {
            MatchStatus::InvalidRequestTarget => $this->responseFactory->createResponse(400),
            MatchStatus::MethodNotAllowed => $this->responseFactory->createResponse(405)
                ->withHeader('Allow', implode(', ', $result->allowedMethods)),
            MatchStatus::OptionsSynthesized => $this->responseFactory->createResponse(204)
                ->withHeader('Allow', implode(', ', $result->allowedMethods)),
            MatchStatus::NotFound => $this->dispatchFallback($request),
            MatchStatus::Found => $this->dispatchMatched($result, $request),
        };
    }

    /**
     * Dispatch the configured fallback handler or return a 404 response.
     *
     * @param ServerRequestInterface $request Incoming HTTP request.
     *
     * @return ResponseInterface
     *
     * @throws DispatchException When fallback handler invocation fails.
     * @throws ReflectionException When service class reflection fails.
     * @throws ContainerExceptionInterface When container resolution fails.
     * @throws NotFoundExceptionInterface When a container service is missing.
     */
    private function dispatchFallback(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->fallbackPlan === null) {
            return $this->responseFactory->createResponse(404);
        }

        $raw = $this->invokePlan($this->fallbackPlan, [], $request);

        return $this->responseAdapter->adapt($raw, $request);
    }

    /**
     * Dispatch a matched route through its middleware pipeline and handler.
     *
     * @param MatchResult $result Successful match result.
     * @param ServerRequestInterface $request Incoming HTTP request.
     *
     * @return ResponseInterface
     *
     * @throws DispatchException When middleware or handler invocation fails.
     * @throws ContainerExceptionInterface When container resolution fails.
     * @throws NotFoundExceptionInterface When a container service is missing.
     */
    private function dispatchMatched(MatchResult $result, ServerRequestInterface $request): ResponseInterface
    {
        $routeId = $result->routeId ?? -1;
        $route = $this->routesById[$routeId];
        $plan = $this->handlerPlans[$routeId];

        $terminal = new readonly class (
            $plan,
            $result->params,
            $this->invokePlan(...),
            $this->responseAdapter,
        ) implements RequestHandlerInterface {
            /**
             * @param array<string, string> $params
             * @param Closure(HandlerPlan, array<string, string>, ServerRequestInterface): mixed $invoker
             */
            public function __construct(
                private HandlerPlan $plan,
                private array $params,
                private Closure $invoker,
                private ResponseAdapterInterface $adapter,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $raw = ($this->invoker)($this->plan, $this->params, $request);

                return $this->adapter->adapt($raw, $request);
            }
        };

        if ($route->middleware !== []) {
            $pipeline = new MiddlewarePipeline($route->middleware, $this->container, $this->middlewareFactory);
            $response = $pipeline->handle($request, $terminal);
        } else {
            $response = $terminal->handle($request);
        }

        if ($result->headFallback) {
            return $response->withBody($this->streamFactory->createStream(''));
        }

        return $response;
    }

    /**
     * Invoke a compiled/analyzed handler plan with bound arguments.
     *
     * @param HandlerPlan $plan Handler plan metadata.
     * @param array<string, string> $routeParams Captured route parameters.
     * @param ServerRequestInterface $request Current HTTP request.
     *
     * @return mixed Raw handler return value.
     *
     * @throws DispatchException When a required parameter, service, or handler cannot be resolved.
     * @throws ReflectionException When service class reflection fails.
     * @throws ContainerExceptionInterface When container resolution fails.
     * @throws NotFoundExceptionInterface When a container service is missing.
     */
    private function invokePlan(HandlerPlan $plan, array $routeParams, ServerRequestInterface $request): mixed
    {
        $args = array_map(
            fn ($p): mixed => match ($p->source) {
                'binding' => $p->bindingValue,
                'route_param' => array_key_exists($p->name, $routeParams)
                    ? ParameterConverter::convertRouteParam($routeParams[$p->name], $p->typeName, $p->name)
                    : ($p->hasDefault ? $p->defaultValue : ($p->allowsNull ? null : throw new DispatchException(sprintf('Missing route parameter "%s".', $p->name)))),
                'request' => $request,
                'service' => $this->resolveService($p->typeName ?? '', $p->hasDefault, $p->defaultValue),
                'default' => $p->defaultValue,
                default => throw new DispatchException(sprintf('Unknown parameter source "%s".', $p->source)),
            },
            $plan->parameters,
        );

        if ($plan->kind === 'closure' && $plan->closure !== null) {
            return ($plan->closure)(...$args);
        }

        if ($plan->kind === 'object_method' && $plan->instance !== null && $plan->methodName !== null) {
            $method = $plan->methodName;

            return $plan->instance->$method(...$args);
        }

        if (($plan->kind === 'controller' || $plan->kind === 'invokable') && $plan->className !== null && $plan->methodName !== null) {
            $instance = $this->resolveService($plan->className, false, null);
            $method = $plan->methodName;

            return $instance->$method(...$args);
        }

        throw new DispatchException('Invalid handler plan.');
    }

    /**
     * Resolve a service or controller class from the PSR-11 container or zero-required-arg constructor.
     *
     * @param string $className Class or interface name.
     * @param bool $hasDefault Whether a fallback default object exists.
     * @param mixed $default Default value.
     *
     * @return object
     *
     * @throws DispatchException When the service cannot be resolved.
     * @throws ReflectionException When class reflection fails.
     * @throws ContainerExceptionInterface When container resolution fails.
     * @throws NotFoundExceptionInterface When a container service is missing.
     */
    private function resolveService(string $className, bool $hasDefault, mixed $default): object
    {
        if ($this->container !== null && $this->container->has($className)) {
            $service = $this->container->get($className);
            if (is_object($service)) {
                return $service;
            }
        }

        if (class_exists($className)) {
            $ref = new ReflectionClass($className);
            $ctor = $ref->getConstructor();
            if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
                return new $className();
            }
        }

        if ($hasDefault && is_object($default)) {
            return $default;
        }

        throw new DispatchException(sprintf('Service "%s" could not be resolved from container.', $className));
    }
}
