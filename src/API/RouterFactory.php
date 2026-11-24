<?php

declare(strict_types=1);

namespace Routir\API;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Routir\Compiler\CompileConfig;
use Routir\Contracts\MiddlewareFactoryInterface;
use Routir\Contracts\ResponseAdapterInterface;
use Routir\Contracts\RouterInterface;
use Routir\Contracts\RouterMode;
use Routir\Exception\RoutirException;
use Routir\Runtime\CompiledRouter;

final class RouterFactory
{
    /**
     * Create a router instance for the requested execution mode.
     *
     * @param RouterMode $mode Execution mode (Interpreted, Compiled, or Auto).
     * @param string|list<string>|null $routes File, directory, or list of route files/directories.
     * @param ?string $artifact Compiled artifact directory path.
     * @param ?ContainerInterface $container Optional PSR-11 DI container.
     * @param ?ResponseAdapterInterface $responseAdapter Optional response adapter.
     * @param ?ResponseFactoryInterface $responseFactory Optional PSR-17 response factory.
     * @param ?StreamFactoryInterface $streamFactory Optional PSR-17 stream factory.
     * @param ?MiddlewareFactoryInterface $middlewareFactory Optional parameterized middleware factory.
     *
     * @return RouterInterface
     *
     * @throws RoutirException When required routes/artifact configuration is missing or invalid.
     */
    public static function create(
        RouterMode $mode,
        string|array|null $routes = null,
        ?string $artifact = null,
        ?ContainerInterface $container = null,
        ?ResponseAdapterInterface $responseAdapter = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?MiddlewareFactoryInterface $middlewareFactory = null,
    ): RouterInterface {
        if ($mode === RouterMode::Compiled) {
            if ($artifact === null || $artifact === '') {
                throw new RoutirException('Compiled mode requires a non-empty artifact directory.');
            }

            return CompiledRouter::load(
                artifactDir: $artifact,
                container: $container,
                responseAdapter: $responseAdapter,
                responseFactory: $responseFactory,
                streamFactory: $streamFactory,
                middlewareFactory: $middlewareFactory,
            );
        }

        if ($mode === RouterMode::Auto && $artifact !== null && $artifact !== '') {
            if (is_file($artifact . '/current/manifest.json') || is_file($artifact . '/manifest.json')) {
                return CompiledRouter::load(
                    artifactDir: $artifact,
                    container: $container,
                    responseAdapter: $responseAdapter,
                    responseFactory: $responseFactory,
                    streamFactory: $streamFactory,
                    middlewareFactory: $middlewareFactory,
                );
            }
        }

        if ($routes === null || $routes === []) {
            throw new RoutirException('Interpreted mode requires a valid route file or directory.');
        }

        $router = new Router(
            container: $container,
            responseAdapter: $responseAdapter,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        RouteLoader::loadInto($router, $routes);
        $router->freeze();

        return $router;
    }

    /**
     * Create a router instance from a CompileConfig.
     *
     * @param CompileConfig $config Compilation and runtime configuration.
     * @param ?ContainerInterface $container Optional PSR-11 DI container.
     * @param ?ResponseAdapterInterface $responseAdapter Optional response adapter.
     * @param ?ResponseFactoryInterface $responseFactory Optional PSR-17 response factory.
     * @param ?StreamFactoryInterface $streamFactory Optional PSR-17 stream factory.
     * @param ?MiddlewareFactoryInterface $middlewareFactory Optional parameterized middleware factory.
     *
     * @return RouterInterface
     *
     * @throws RoutirException When required routes/artifact configuration is missing or invalid.
     */
    public static function fromConfig(
        CompileConfig $config,
        ?ContainerInterface $container = null,
        ?ResponseAdapterInterface $responseAdapter = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?MiddlewareFactoryInterface $middlewareFactory = null,
    ): RouterInterface {
        return self::create(
            mode: $config->mode,
            routes: $config->inputs,
            artifact: $config->outputDir,
            container: $container,
            responseAdapter: $responseAdapter,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
            middlewareFactory: $middlewareFactory,
        );
    }
}
