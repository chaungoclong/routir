<?php

declare(strict_types=1);

namespace Routir\Middleware;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Routir\Contracts\MiddlewareFactoryInterface;
use Routir\Exception\DispatchException;

final class MiddlewarePipeline
{
    /**
     * @param list<string> $middlewareEntries
     */
    public function __construct(
        private readonly array $middlewareEntries,
        private readonly ?ContainerInterface $container = null,
        private readonly ?MiddlewareFactoryInterface $factory = null,
    ) {
    }

    /**
     * Execute the middleware pipeline around a terminal request handler.
     *
     * @param ServerRequestInterface $request Incoming HTTP request.
     * @param RequestHandlerInterface $terminal Terminal route handler.
     *
     * @return ResponseInterface
     *
     * @throws DispatchException When a middleware cannot be resolved.
     * @throws ContainerExceptionInterface When container resolution fails.
     * @throws NotFoundExceptionInterface When a container service is missing.
     */
    public function handle(ServerRequestInterface $request, RequestHandlerInterface $terminal): ResponseInterface
    {
        $runner = new IndexedPipelineHandler(
            $this->middlewareEntries,
            0,
            $terminal,
            $this->resolveMiddleware(...),
        );

        return $runner->handle($request);
    }

    /**
     * Resolve a middleware alias/class entry into a PSR-15 MiddlewareInterface.
     *
     * @param string $entry Middleware class name, service ID, or parameterized entry.
     *
     * @return MiddlewareInterface
     *
     * @throws DispatchException When the entry cannot be resolved to a MiddlewareInterface.
     * @throws ContainerExceptionInterface When container resolution fails.
     * @throws NotFoundExceptionInterface When a container service is missing.
     */
    private function resolveMiddleware(string $entry): MiddlewareInterface
    {
        $colonPos = strpos($entry, ':');
        $id = $colonPos === false ? $entry : substr($entry, 0, $colonPos);
        $params = $colonPos === false ? [] : explode(',', substr($entry, $colonPos + 1));

        if ($params !== [] && $this->factory !== null) {
            return $this->factory->create($id, $params);
        }

        $instance = null;
        if ($this->container !== null && $this->container->has($id)) {
            $instance = $this->container->get($id);
        } elseif (class_exists($id)) {
            $instance = $params !== [] ? new $id(...$params) : new $id();
        }

        if (!$instance instanceof MiddlewareInterface) {
            throw new DispatchException(sprintf('Middleware "%s" could not be resolved to a Psr\\Http\\Server\\MiddlewareInterface.', $entry));
        }

        return $instance;
    }
}
