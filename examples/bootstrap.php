<?php

declare(strict_types=1);

namespace Examples\Shared;

use Routir\Contracts\ResponseAdapterInterface;
use Routir\DependencyInjection\SimpleContainer;
use Routir\Runtime\MinimalPsr17Factory;
use Routir\Runtime\MinimalResponse;
use Routir\Runtime\MinimalStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ArticleStore
{
    public function label(): string
    {
        return 'store';
    }
}

final class ArticleController
{
    public function __construct(private readonly ArticleStore $store)
    {
    }

    /** @return array<string, mixed> */
    public function store(ServerRequestInterface $request): array
    {
        return [
            'action' => 'created',
            'via' => $this->store->label(),
            'method' => $request->getMethod(),
            'role' => $request->getAttribute('role', 'guest'),
        ];
    }

    /** @return array<string, mixed> */
    public function update(int $id): array
    {
        return ['action' => 'replaced', 'id' => $id];
    }

    /** @return array<string, mixed> */
    public function patch(int $id): array
    {
        return ['action' => 'patched', 'id' => $id];
    }
}

final class DeleteArticleHandler
{
    public function __construct(private readonly ArticleStore $store)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(int $id): array
    {
        return ['action' => 'deleted', 'id' => $id, 'from' => $this->store->label()];
    }
}

final class RoleMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $role = 'member')
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute('role', $this->role))
            ->withHeader('X-Role', $this->role);
    }
}

final class TimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = hrtime(true);
        $res = $handler->handle($request);
        $us = (hrtime(true) - $start) / 1000.0;

        return $res->withHeader('X-Elapsed-Us', sprintf('%.1f', $us));
    }
}

final class BlockMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return new MinimalResponse(403, body: new MinimalStream('Access denied'));
    }
}

final class JsonResponseAdapter implements ResponseAdapterInterface
{
    public function __construct(
        private readonly MinimalPsr17Factory $factory = new MinimalPsr17Factory(),
    ) {
    }

    public function adapt(mixed $result, ServerRequestInterface $request): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        if (is_array($result)) {
            $res = $this->factory->createResponse(200);
            $res->getBody()->write(json_encode($result, JSON_THROW_ON_ERROR));

            return $res->withHeader('Content-Type', 'application/json');
        }

        if (is_string($result)) {
            $res = $this->factory->createResponse(200);
            $res->getBody()->write($result);

            return $res->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        throw new \RuntimeException('Unsupported handler return type.');
    }
}

function createContainer(): SimpleContainer
{
    $store = new ArticleStore();

    return new SimpleContainer([
        ArticleStore::class => $store,
        ArticleController::class => static fn (SimpleContainer $c): ArticleController => new ArticleController($c->get(ArticleStore::class)),
        DeleteArticleHandler::class => static fn (SimpleContainer $c): DeleteArticleHandler => new DeleteArticleHandler($c->get(ArticleStore::class)),
        'timing' => static fn (): TimingMiddleware => new TimingMiddleware(),
        'block' => static fn (): BlockMiddleware => new BlockMiddleware(),
    ]);
}
