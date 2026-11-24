<?php

declare(strict_types=1);

use Routir\API\Router;
use Routir\Exception\RouteNotFoundException;
use Routir\Exception\UrlGenerationException;

test('generates urls with params optional wildcard and query', function (): void {
    $router = new Router();
    $router->get('/users/{id:int}', static fn (): string => 'ok')->name('users.show');
    $router->get('/archive/{year:int?}/{month:int?}', static fn (): string => 'ok')->name('archive');
    $router->get('/files/{path:*}', static fn (): string => 'ok')->name('files');

    expect($router->route('users.show', ['id' => 42, 'tab' => 'posts']))->toBe('/users/42?tab=posts')
        ->and($router->route('archive'))->toBe('/archive')
        ->and($router->route('archive', ['year' => 2026]))->toBe('/archive/2026')
        ->and($router->route('archive', ['year' => 2026, 'month' => 11]))->toBe('/archive/2026/11')
        ->and($router->route('files', ['path' => 'docs/hello world/readme.md']))->toBe('/files/docs/hello%20world/readme.md');
});

test('rejects unknown route', function (): void {
    $router = new Router();
    expect(static fn () => $router->route('missing'))->toThrow(RouteNotFoundException::class);
});

test('rejects optional gap', function (): void {
    $router = new Router();
    $router->get('/archive/{year:int?}/{month:int?}', static fn (): string => 'ok')->name('archive');

    expect(static fn () => $router->route('archive', ['month' => 11]))->toThrow(UrlGenerationException::class);
});

test('rejects slash in non-wildcard param', function (): void {
    $router = new Router();
    $router->get('/users/{slug:slug}', static fn (): string => 'ok')->name('users.slug');

    expect(static fn () => $router->route('users.slug', ['slug' => 'a/b']))->toThrow(UrlGenerationException::class);
});

test('rejects constraint failure', function (): void {
    $router = new Router();
    $router->get('/users/{id:int}', static fn (): string => 'ok')->name('users.show');

    expect(static fn () => $router->route('users.show', ['id' => 'abc']))->toThrow(UrlGenerationException::class);
});
