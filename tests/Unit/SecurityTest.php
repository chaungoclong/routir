<?php

declare(strict_types=1);

use Routir\API\Router;
use Routir\Compiler\Compiler;
use Routir\Compiler\CompilerOptions;
use Routir\Exception\DispatchException;
use Routir\Exception\RoutirException;
use Routir\Exception\SemanticException;
use Routir\Runtime\CompiledRouter;
use Routir\Runtime\ConstraintRegistry;

test('rejects code injection in pattern', function (): void {
    $router = new Router();
    $router->get("/users/'; system('id'); //", static fn (): string => 'no');

    expect(static fn () => $router->matchRequest('GET', '/users/1'))
        ->toThrow(SemanticException::class);
});

test('quotes malicious defaults and bindings safely', function (): void {
    $router = new Router();
    $payload = "'; unlink('/etc/passwd'); //";
    $router->get('/test/{x?}', static fn (string $x): string => $x)->defaults('x', $payload);

    $dir = sys_get_temp_dir() . '/routir_sec_art_' . uniqid('', true);
    try {
        new Compiler()->compileToDirectory($router->getCollection(), $dir, new CompilerOptions(optimizationLevel: 2));
        $compiled = CompiledRouter::load($dir);
        $res = $compiled->matchRequest('GET', '/test');
        expect($res->params)->toBe(['x' => $payload]);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
});

test('enforces regex segment length bound', function (): void {
    $compiled = ConstraintRegistry::compileRegex('[a-z]+');
    $long = str_repeat('a', ConstraintRegistry::MAX_SEGMENT_LENGTH + 1);

    expect(static fn () => ConstraintRegistry::matchesRegex($compiled, $long))
        ->toThrow(DispatchException::class);
});

test('refuses world-writable artifact', function (): void {
    $router = new Router();
    $router->get('/', static fn (): string => 'ok');

    $dir = sys_get_temp_dir() . '/routir_sec_perm_' . uniqid('', true);
    try {
        new Compiler()->compileToDirectory($router->getCollection(), $dir, new CompilerOptions(optimizationLevel: 0));
        chmod($dir . '/current/matcher.php', 0666);

        expect(static fn () => CompiledRouter::load($dir))
            ->toThrow(RoutirException::class);
    } finally {
        @chmod($dir . '/current/matcher.php', 0644);
        exec('rm -rf ' . escapeshellarg($dir));
    }
});
