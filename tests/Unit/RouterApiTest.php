<?php

declare(strict_types=1);

use Routir\API\GroupBuilder;
use Routir\API\RouteBuilder;
use Routir\API\Router;
use Routir\Exception\RoutirException;

test('group builder and route builder are distinct', function (): void {
    $router = new Router();
    $groupBuilder = $router->prefix('/api');
    $routeBuilder = $router->get('/ping', static fn (): string => 'pong');

    expect($groupBuilder)->toBeInstanceOf(GroupBuilder::class)
        ->and($routeBuilder)->toBeInstanceOf(RouteBuilder::class);
});

test('group state unwinds in finally on exception', function (): void {
    $router = new Router();

    try {
        $router->prefix('/admin')->name('admin.')->middleware('auth')->group(static function (Router $r): void {
            $r->get('/dashboard', static fn (): string => 'dash')->name('dashboard');
            throw new RuntimeException('boom');
        });
        expect(false)->toBeTrue('Expected RuntimeException');
    } catch (RuntimeException) {
        // expected
    }

    $router->get('/public', static fn (): string => 'pub')->name('public');

    $routes = $router->getRoutes();
    expect($routes)->toHaveCount(2);
    expect($routes[0]->pattern)->toBe('/admin/dashboard')
        ->and($routes[0]->name)->toBe('admin.dashboard')
        ->and($routes[0]->middleware)->toBe(['auth']);
    expect($routes[1]->pattern)->toBe('/public')
        ->and($routes[1]->name)->toBe('public')
        ->and($routes[1]->middleware)->toBe([]);
});

test('cannot mutate after freeze', function (): void {
    $router = new Router();
    $router->get('/', static fn (): string => 'ok');
    $router->freeze();

    expect(static fn () => $router->get('/after', static fn (): string => 'no'))
        ->toThrow(RoutirException::class);
});
