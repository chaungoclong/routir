<?php

declare(strict_types=1);

use Routir\API\Router;
use Routir\Exception\SemanticException;
use Routir\Semantic\SemanticAnalyzer;

test('flattens groups and middleware with exclusions', function (): void {
    $router = new Router();
    $router->middlewareAlias('web', ['cookies', 'session']);
    $router->middlewareAlias('auth', 'App\\AuthMiddleware');

    $router->prefix('/api')->name('api.')->middleware(['web', 'auth:api'])->group(function (Router $r): void {
        $r->get('/users', static fn (): string => 'users')
            ->name('users.index')
            ->middleware('throttle:60')
            ->withoutMiddleware('auth');
    });

    $result = new SemanticAnalyzer()->analyze($router->getCollection());
    expect($result->routes)->toHaveCount(1);
    $route = $result->routes[0];
    expect($route->pattern)->toBe('/api/users')
        ->and($route->name)->toBe('api.users.index')
        ->and($route->middleware)->toBe(['cookies', 'session', 'throttle:60']);
});

test('rejects middleware cycle', function (): void {
    $router = new Router();
    $router->middlewareAlias('a', 'b');
    $router->middlewareAlias('b', 'a');
    $router->get('/', static fn (): string => 'ok')->middleware('a');

    expect(static fn () => new SemanticAnalyzer()->analyze($router->getCollection()))
        ->toThrow(SemanticException::class);
});

test('rejects duplicate parameter names', function (): void {
    $router = new Router();
    $router->get('/users/{id}/posts/{id}', static fn (): string => 'ok');

    expect(static fn () => new SemanticAnalyzer()->analyze($router->getCollection()))
        ->toThrow(SemanticException::class);
});

test('rejects non-trailing optional parameter', function (): void {
    $router = new Router();
    $router->get('/users/{id?}/posts', static fn (): string => 'ok');

    expect(static fn () => new SemanticAnalyzer()->analyze($router->getCollection()))
        ->toThrow(SemanticException::class);
});

test('rejects conflicting inline and fluent constraints', function (): void {
    $router = new Router();
    $router->get('/users/{id:int}', static fn (): string => 'ok')->whereAlpha('id');

    expect(static fn () => new SemanticAnalyzer()->analyze($router->getCollection()))
        ->toThrow(SemanticException::class);
});

test('rejects duplicate route names', function (): void {
    $router = new Router();
    $router->get('/a', static fn (): string => 'a')->name('dup');
    $router->get('/b', static fn (): string => 'b')->name('dup');

    expect(static fn () => new SemanticAnalyzer()->analyze($router->getCollection()))
        ->toThrow(SemanticException::class);
});

test('rejects duplicate route signatures', function (): void {
    $router = new Router();
    $router->get('/users/{id:int}', static fn (): string => 'a');
    $router->get('/users/{userId:int}', static fn (): string => 'b');

    expect(static fn () => new SemanticAnalyzer()->analyze($router->getCollection()))
        ->toThrow(SemanticException::class);
});
