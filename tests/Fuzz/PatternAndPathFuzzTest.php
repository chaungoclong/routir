<?php

declare(strict_types=1);

use Routir\API\Router;
use Routir\Compiler\Compiler;
use Routir\Compiler\CompilerOptions;
use Routir\Exception\InvalidRequestTargetException;
use Routir\Exception\ParseException;
use Routir\Frontend\Parser\Parser;
use Routir\Interpreter\ReferenceMatcher;
use Routir\Runtime\CompiledRouter;
use Routir\Runtime\PathNormalizer;
use Routir\Semantic\SemanticAnalyzer;

test('fuzz patterns and paths with fixed seed', function (): void {
    mt_srand(20261124);
    $parser = new Parser();

    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789/-_.~{}:?* %!@#';
    $lenAlpha = strlen($alphabet);

    for ($i = 0; $i < 200; $i++) {
        $len = mt_rand(0, 32);
        $pat = '';
        for ($k = 0; $k < $len; $k++) {
            $pat .= $alphabet[mt_rand(0, $lenAlpha - 1)];
        }

        try {
            $ast = $parser->parse($pat);
            expect($ast->rawPattern)->toBe($pat);
        } catch (ParseException $e) {
            expect($e->diagnostics->errors())->not->toBeEmpty();
        }

        try {
            $segs = PathNormalizer::normalize($pat);
            foreach ($segs as $s) {
                expect($s)->not->toBe('.')
                    ->and($s)->not->toBe('..')
                    ->and(str_contains($s, '/'))->toBeFalse()
                    ->and(str_contains($s, '\\'))->toBeFalse();
            }
        } catch (InvalidRequestTargetException) {
            expect(true)->toBeTrue();
        }
    }
});

test('fuzz differential parity between reference and O2 compiled', function (): void {
    mt_srand(424242);
    $router = new Router();
    $router->get('/', static fn (): string => 'root');
    $router->get('/a/{x:int}/{y?}', static fn (): string => 'a');
    $router->get('/a/b/{z:alpha}', static fn (): string => 'ab');
    $router->get('/files/{rest:*}', static fn (): string => 'f');

    $semantic = new SemanticAnalyzer()->analyze($router->getCollection());
    $ref = new ReferenceMatcher($semantic);

    $dir = sys_get_temp_dir() . '/routir_fuzz_art_' . uniqid('', true);
    try {
        new Compiler()->compileToDirectory($router->getCollection(), $dir, new CompilerOptions(optimizationLevel: 2));
        $compiled = CompiledRouter::load($dir);

        $tokens = ['a', 'b', '123', 'xyz', 'hello%20world', '%ZZ', '..', '.', '%2F', ''];
        $methods = ['GET', 'POST', 'HEAD', 'OPTIONS', 'DELETE'];

        for ($i = 0; $i < 150; $i++) {
            $method = $methods[array_rand($methods)];
            $segCount = mt_rand(0, 4);
            $parts = [];
            for ($s = 0; $s < $segCount; $s++) {
                $parts[] = $tokens[array_rand($tokens)];
            }
            $target = '/' . implode('/', $parts);

            $r1 = $ref->match($method, $target);
            $r2 = $compiled->matchRequest($method, $target);

            expect($r2->status)->toBe($r1->status, "Status mismatch for $method $target")
                ->and($r2->routeId)->toBe($r1->routeId, "RouteId mismatch for $method $target")
                ->and($r2->params)->toBe($r1->params, "Params mismatch for $method $target")
                ->and($r2->allowedMethods)->toBe($r1->allowedMethods, "Allowed mismatch for $method $target");
        }
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
});
