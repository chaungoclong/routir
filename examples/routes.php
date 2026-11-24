<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Examples\Shared\ArticleController;
use Examples\Shared\DeleteArticleHandler;
use Examples\Shared\RoleMiddleware;
use Routir\API\Router;
use Routir\Runtime\MinimalResponse;
use Routir\Runtime\MinimalStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$cacheTag = 'v1';

// Middleware aliases
$router->middlewareAlias('auth', RoleMiddleware::class);

// Root & static/param routes (arrow function + scalar-capturing closure)
$router->get('/', static fn (): string => 'OK')->name('home');
$router->get('/users/{id:int}', static fn (int $id): array => ['user' => $id])->name('users.show');

// Group with prefix, name prefix, middleware & exclusions
$router->prefix('/api')
    ->name('api.')
    ->middleware(['timing', 'auth:admin'])
    ->group(static function (Router $r) use ($cacheTag): void {
        // GET -> arrow function
        $r->get('/articles/{id:int}', static fn (int $id): array => ['action' => 'show', 'id' => $id])
            ->name('articles.show');

        // POST -> [Controller::class, 'method'] with DI + ServerRequestInterface + role attribute from middleware
        $r->post('/articles', [ArticleController::class, 'store'])
            ->name('articles.store');

        // PUT & PATCH -> [Controller::class, 'method']
        $r->put('/articles/{id:int}', [ArticleController::class, 'update'])
            ->name('articles.update');
        $r->patch('/articles/{id:int}', [ArticleController::class, 'patch'])
            ->name('articles.patch');

        // DELETE -> Invokable class (__invoke) with DI
        $r->delete('/articles/{id:int}', DeleteArticleHandler::class)
            ->name('articles.delete');

        // OPTIONS -> explicit OPTIONS closure
        $r->options('/articles', static fn (): string => 'OPTIONS /api/articles supported');

        // HEAD -> explicit HEAD closure
        $r->head('/health', static fn (): string => 'ignored-head-body');
        $r->get('/health', static fn (): string => 'healthy')
            ->withoutMiddleware('auth')
            ->name('health');

        // Short-circuit middleware example
        $r->get('/secret', static fn (): string => 'never')
            ->middleware('block')
            ->name('secret');

        // match() -> multi-method including custom HTTP method + closure with scalar capture
        $r->match(['GET', 'POST', 'PURGE'], '/cache/{key:slug}', static function (string $key, ServerRequestInterface $req) use ($cacheTag): array {
            return ['method' => $req->getMethod(), 'tag' => $cacheTag, 'key' => $key];
        })->name('cache');

        // any() -> all standard methods
        $r->any('/echo', static fn (ServerRequestInterface $req): array => ['echo' => $req->getMethod()])
            ->name('echo');

        // Wildcard route
        $r->get('/files/{path:*}', static fn (string $path): array => ['file' => $path])
            ->name('files.show');
    });

// Custom JSON 404 fallback
$router->fallback(static fn (ServerRequestInterface $req): ResponseInterface => new MinimalResponse(
    404,
    body: new MinimalStream(json_encode(['error' => 'Not found', 'path' => $req->getUri()->getPath()], JSON_THROW_ON_ERROR)),
)->withHeader('Content-Type', 'application/json'));
