<?php

declare(strict_types=1);

namespace Routir\Runtime;

use JsonException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Routir\Contracts\MatcherInterface;
use Routir\Contracts\MatchResult;
use Routir\Contracts\MatchStatus;
use Routir\Contracts\MiddlewareFactoryInterface;
use Routir\Contracts\ResponseAdapterInterface;
use Routir\Contracts\RouterInterface;
use Routir\Exception\DispatchException;
use Routir\Exception\RoutirException;
use Routir\Middleware\MiddlewarePipeline;

final class CompiledRouter implements RouterInterface
{
    public const string SUPPORTED_SCHEMA_VERSION = '0.1';

    private readonly ResponseAdapterInterface $responseAdapter;
    private readonly ResponseFactoryInterface $responseFactory;
    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param array{topologies: list<list<string>>, routeToTopology: array<int, int>} $pipelines
     * @param array<int, array{pattern: string, methods: list<string>, name: ?string, hasMiddleware: bool}> $metadata
     */
    private function __construct(
        private readonly MatcherInterface $matcher,
        private readonly object $handlers,
        private readonly array $pipelines,
        private readonly object $urlGenerator,
        private readonly array $metadata,
        private readonly ?ContainerInterface $container = null,
        ?ResponseAdapterInterface $responseAdapter = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly ?MiddlewareFactoryInterface $middlewareFactory = null,
    ) {
        $psr17 = new MinimalPsr17Factory();
        $this->responseAdapter = $responseAdapter ?? new DefaultResponseAdapter($psr17);
        $this->responseFactory = $responseFactory ?? $psr17;
        $this->streamFactory = $streamFactory ?? $psr17;
    }

    /**
     * Load a compiled router from an artifact directory.
     *
     * @param string $artifactDir Artifact root or release directory.
     * @param ?ContainerInterface $container Optional PSR-11 DI container.
     * @param ?ResponseAdapterInterface $responseAdapter Optional response adapter.
     * @param ?ResponseFactoryInterface $responseFactory Optional PSR-17 response factory.
     * @param ?StreamFactoryInterface $streamFactory Optional PSR-17 stream factory.
     * @param ?MiddlewareFactoryInterface $middlewareFactory Optional parameterized middleware factory.
     *
     * @return self
     *
     * @throws RoutirException When artifacts are missing, world-writable, or incompatible.
     */
    public static function load(
        string $artifactDir,
        ?ContainerInterface $container = null,
        ?ResponseAdapterInterface $responseAdapter = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?MiddlewareFactoryInterface $middlewareFactory = null,
    ): self {
        $resolvedDir = $artifactDir;
        if (is_file($artifactDir . '/current/manifest.json')) {
            $resolvedDir = $artifactDir . '/current';
        }

        $manifestPath = $resolvedDir . '/manifest.json';
        if (!is_file($manifestPath)) {
            throw new RoutirException(sprintf('Compiled artifact manifest not found at "%s".', $manifestPath));
        }

        self::assertSafePermissions($resolvedDir);
        self::assertSafePermissions($manifestPath);

        $rawManifest = file_get_contents($manifestPath);
        if ($rawManifest === false) {
            throw new RoutirException(sprintf('Unable to read manifest "%s".', $manifestPath));
        }

        try {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($rawManifest, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RoutirException('Invalid compiled artifact manifest JSON: ' . $e->getMessage(), 0, $e);
        }

        $schemaVersion = $manifest['schemaVersion'] ?? null;
        if ($schemaVersion !== self::SUPPORTED_SCHEMA_VERSION) {
            $actual = is_string($schemaVersion) ? $schemaVersion : get_debug_type($schemaVersion);
            throw new RoutirException(sprintf(
                'Incompatible artifact schema version "%s" (expected "%s").',
                $actual,
                self::SUPPORTED_SCHEMA_VERSION,
            ));
        }

        $requiredFiles = ['matcher.php', 'handlers.php', 'pipelines.php', 'url-generator.php', 'metadata.php'];
        foreach ($requiredFiles as $file) {
            $path = $resolvedDir . '/' . $file;
            if (!is_file($path)) {
                throw new RoutirException(sprintf('Missing compiled artifact file "%s".', $path));
            }
            self::assertSafePermissions($path);
        }

        /** @var MatcherInterface $matcher */
        $matcher = require $resolvedDir . '/matcher.php';
        /** @var object $handlers */
        $handlers = require $resolvedDir . '/handlers.php';
        /** @var array{topologies: list<list<string>>, routeToTopology: array<int, int>} $pipelines */
        $pipelines = require $resolvedDir . '/pipelines.php';
        /** @var object $urlGenerator */
        $urlGenerator = require $resolvedDir . '/url-generator.php';
        /** @var array<int, array{pattern: string, methods: list<string>, name: ?string, hasMiddleware: bool}> $metadata */
        $metadata = require $resolvedDir . '/metadata.php';

        return new self(
            matcher: $matcher,
            handlers: $handlers,
            pipelines: $pipelines,
            urlGenerator: $urlGenerator,
            metadata: $metadata,
            container: $container,
            responseAdapter: $responseAdapter,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
            middlewareFactory: $middlewareFactory,
        );
    }

    public function matchRequest(string $method, string $requestTarget): MatchResult
    {
        return $this->matcher->match($method, $requestTarget);
    }

    /**
     * Dispatch an incoming PSR-7 request using compiled artifacts.
     *
     * @param ServerRequestInterface $request Incoming HTTP request.
     *
     * @return ResponseInterface
     *
     * @throws DispatchException When middleware or handler invocation fails.
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
     * @param array<string, mixed> $params
     */
    public function route(string $name, array $params = []): string
    {
        /** @phpstan-ignore-next-line */
        return $this->urlGenerator->generate($name, $params);
    }

    public function has(string $name): bool
    {
        /** @phpstan-ignore-next-line */
        return $this->urlGenerator->has($name);
    }

    /**
     * @return array<int, array{pattern: string, methods: list<string>, name: ?string, hasMiddleware: bool}>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    private function dispatchFallback(ServerRequestInterface $request): ResponseInterface
    {
        /** @phpstan-ignore-next-line */
        if (!$this->handlers->hasFallback()) {
            return $this->responseFactory->createResponse(404);
        }

        /** @phpstan-ignore-next-line */
        $raw = $this->handlers->invokeFallback($request, $this->container);

        return $this->responseAdapter->adapt($raw, $request);
    }

    /**
     * Dispatch a matched route through its compiled middleware topology and handler.
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
        $params = $result->params;

        $topId = $this->pipelines['routeToTopology'][$routeId] ?? null;
        $mwList = $topId !== null ? ($this->pipelines['topologies'][$topId] ?? []) : [];

        if ($mwList === []) {
            /** @phpstan-ignore-next-line */
            $raw = $this->handlers->invoke($routeId, $params, $request, $this->container);
            $response = $this->responseAdapter->adapt($raw, $request);
        } else {
            $handlers = $this->handlers;
            $container = $this->container;
            $adapter = $this->responseAdapter;

            $terminal = new readonly class (
                $routeId,
                $params,
                $handlers,
                $container,
                $adapter,
            ) implements RequestHandlerInterface {
                /**
                 * @param array<string, string> $params
                 */
                public function __construct(
                    private int $routeId,
                    private array $params,
                    private object $handlers,
                    private ?ContainerInterface $container,
                    private ResponseAdapterInterface $adapter,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    /** @phpstan-ignore-next-line */
                    $raw = $this->handlers->invoke($this->routeId, $this->params, $request, $this->container);

                    return $this->adapter->adapt($raw, $request);
                }
            };

            $pipeline = new MiddlewarePipeline($mwList, $this->container, $this->middlewareFactory);
            $response = $pipeline->handle($request, $terminal);
        }

        if ($result->headFallback) {
            return $response->withBody($this->streamFactory->createStream(''));
        }

        return $response;
    }

    /**
     * Verify that an artifact path is not writable by untrusted users.
     *
     * @param string $path File or directory path.
     *
     * @return void
     *
     * @throws RoutirException When the path is world-writable.
     */
    private static function assertSafePermissions(string $path): void
    {
        $perms = @fileperms($path);
        if ($perms !== false && ($perms & 0x0002) !== 0) {
            throw new RoutirException(sprintf('Refusing to load world-writable artifact "%s".', $path));
        }
    }
}
