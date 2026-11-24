<?php

declare(strict_types=1);

use Routir\Analysis\IRVerifier;
use Routir\API\Router;
use Routir\Backend\PHP\PHPMatcherEmitter;
use Routir\Contracts\MatcherInterface;
use Routir\Interpreter\ReferenceMatcher;
use Routir\Semantic\SemanticAnalyzer;
use Routir\Transform\Lowering\HIRBuilder;
use Routir\Transform\Lowering\LIRLowering;
use Routir\Transform\Lowering\MIRLowering;

test('reference and O0 compiled matcher have identical semantics', function (): void {
    $router = new Router();
    $router->get('/', static fn (): string => 'root')->name('root');
    $router->get('/users', static fn (): string => 'users.index')->name('users.index');
    $router->get('/users/{id?}', static fn (): string => 'users.opt')->defaults('id', 'me')->name('users.opt');
    $router->get('/users/{id:int}', static fn (): string => 'users.int')->name('users.int');
    $router->post('/users', static fn (): string => 'users.store');
    $router->get('/{any}/posts', static fn (): string => 'any.posts');
    $router->get('/{user:alpha}/settings', static fn (): string => 'alpha.settings');
    $router->get('/admin/posts', static fn (): string => 'admin.posts');
    $router->get('/files/{path:*}', static fn (): string => 'files');
    $router->get('/tags/{tag}', static fn (): string => 'tags')->whereIn('tag', ['php', 'rust']);
    $router->get('/items/{code}', static fn (): string => 'items')->where('code', '[A-Z]{3}-[0-9]{2}');
    $router->head('/ping', static fn (): string => 'head-ping');
    $router->get('/ping', static fn (): string => 'get-ping');

    $semantic = new SemanticAnalyzer()->analyze($router->getCollection());
    $refMatcher = new ReferenceMatcher($semantic);

    $hir = new HIRBuilder()->build($semantic);
    $mir = new MIRLowering()->lower($hir);
    $lir = new LIRLowering()->lower($mir);
    new IRVerifier()->verify($lir);

    $phpCode = new PHPMatcherEmitter()->emit($lir);
    $tmpFile = sys_get_temp_dir() . '/routir_o0_matcher_' . uniqid('', true) . '.php';
    file_put_contents($tmpFile, $phpCode);

    try {
        /** @var MatcherInterface $compiledMatcher */
        $compiledMatcher = require $tmpFile;

        $cases = [
            ['GET', '/'],
            ['GET', '/?a=1#b'],
            ['GET', '/users'],
            ['GET', '/users/42'],
            ['GET', '/users/alice'],
            ['GET', '/users/'],
            ['POST', '/users'],
            ['DELETE', '/users'],
            ['OPTIONS', '/users'],
            ['HEAD', '/users'],
            ['HEAD', '/ping'],
            ['GET', '/ping'],
            ['GET', '/admin/posts'],
            ['GET', '/john/posts'],
            ['GET', '/john/settings'],
            ['GET', '/123/posts'],
            ['GET', '/files/a/b%20c/d.txt'],
            ['GET', '/files'],
            ['GET', '/tags/php'],
            ['GET', '/tags/go'],
            ['GET', '/items/ABC-12'],
            ['GET', '/items/abc-12'],
            ['GET', '/unknown'],
            ['GET', '/%ZZ'],
            ['GET', '/a%2Fb'],
            ['GET', '/..'],
        ];

        foreach ($cases as [$method, $target]) {
            $expected = $refMatcher->match($method, $target);
            $actual = $compiledMatcher->match($method, $target);

            expect($actual->status)->toBe($expected->status, "Status mismatch for $method $target")
                ->and($actual->routeId)->toBe($expected->routeId, "RouteId mismatch for $method $target")
                ->and($actual->params)->toBe($expected->params, "Params mismatch for $method $target")
                ->and($actual->allowedMethods)->toBe($expected->allowedMethods, "AllowedMethods mismatch for $method $target")
                ->and($actual->headFallback)->toBe($expected->headFallback, "HeadFallback mismatch for $method $target");
        }
    } finally {
        @unlink($tmpFile);
    }
});
