<?php

declare(strict_types=1);

use Routir\API\Router;
use Routir\Contracts\MatchStatus;
use Routir\Interpreter\ReferenceMatcher;
use Routir\Semantic\SemanticAnalyzer;

test('precedence and deeper fallback', function (): void {
    $router = new Router();
    $router->get('/{any}/posts', static fn (): string => 'fallback')->name('fallback'); // id 0
    $router->get('/{user:alpha}/settings', static fn (): string => 'alpha-settings')->name('alpha'); // id 1
    $router->get('/admin/posts', static fn (): string => 'static')->name('static'); // id 2

    $semantic = new SemanticAnalyzer()->analyze($router->getCollection());
    $matcher = new ReferenceMatcher($semantic);

    $res1 = $matcher->match('GET', '/admin/posts');
    expect($res1->status)->toBe(MatchStatus::Found)
        ->and($res1->routeId)->toBe(2);

    $res2 = $matcher->match('GET', '/john/posts');
    expect($res2->status)->toBe(MatchStatus::Found)
        ->and($res2->routeId)->toBe(0)
        ->and($res2->params)->toBe(['any' => 'john']);
});

test('exact terminal beats omitted optional suffix', function (): void {
    $router = new Router();
    $router->get('/users/{id?}', static fn (): string => 'opt')->defaults('id', 'me'); // id 0
    $router->get('/users', static fn (): string => 'exact'); // id 1

    $matcher = new ReferenceMatcher(new SemanticAnalyzer()->analyze($router->getCollection()));

    $resExact = $matcher->match('GET', '/users');
    expect($resExact->status)->toBe(MatchStatus::Found)
        ->and($resExact->routeId)->toBe(1);

    $resOpt = $matcher->match('GET', '/users/42');
    expect($resOpt->status)->toBe(MatchStatus::Found)
        ->and($resOpt->routeId)->toBe(0)
        ->and($resOpt->params)->toBe(['id' => '42']);
});

test('optional default applied only when omitted', function (): void {
    $router = new Router();
    $router->get('/posts/{page:int?}', static fn (): string => 'ok')->defaults('page', '1');

    $matcher = new ReferenceMatcher(new SemanticAnalyzer()->analyze($router->getCollection()));

    $resOmitted = $matcher->match('GET', '/posts');
    expect($resOmitted->status)->toBe(MatchStatus::Found)
        ->and($resOmitted->params)->toBe(['page' => '1']);

    $resProvided = $matcher->match('GET', '/posts/5');
    expect($resProvided->status)->toBe(MatchStatus::Found)
        ->and($resProvided->params)->toBe(['page' => '5']);
});

test('wildcard captures decoded segments joined with slash', function (): void {
    $router = new Router();
    $router->get('/files/{path:*}', static fn (): string => 'ok');

    $matcher = new ReferenceMatcher(new SemanticAnalyzer()->analyze($router->getCollection()));

    $res = $matcher->match('GET', '/files/docs/hello%20world/spec.md');
    expect($res->status)->toBe(MatchStatus::Found)
        ->and($res->params)->toBe(['path' => 'docs/hello world/spec.md']);

    $resEmpty = $matcher->match('GET', '/files');
    expect($resEmpty->status)->toBe(MatchStatus::NotFound);
});

test('head options and 405 semantics', function (): void {
    $router = new Router();
    $router->get('/items', static fn (): string => 'get');
    $router->post('/items', static fn (): string => 'post');

    $matcher = new ReferenceMatcher(new SemanticAnalyzer()->analyze($router->getCollection()));

    $head = $matcher->match('HEAD', '/items');
    expect($head->status)->toBe(MatchStatus::Found)
        ->and($head->routeId)->toBe(0)
        ->and($head->headFallback)->toBeTrue();

    $opts = $matcher->match('OPTIONS', '/items');
    expect($opts->status)->toBe(MatchStatus::OptionsSynthesized)
        ->and($opts->allowedMethods)->toBe(['GET', 'HEAD', 'POST', 'OPTIONS']);

    $del = $matcher->match('DELETE', '/items');
    expect($del->status)->toBe(MatchStatus::MethodNotAllowed)
        ->and($del->allowedMethods)->toBe(['GET', 'HEAD', 'POST', 'OPTIONS']);

    $bad = $matcher->match('GET', '/items/%ZZ');
    expect($bad->status)->toBe(MatchStatus::InvalidRequestTarget);
});
