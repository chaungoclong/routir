<?php

declare(strict_types=1);

use Routir\API\Router;
use Routir\Compiler\Compiler;
use Routir\Compiler\CompilerOptions;

test('build is deterministic across runs', function (): void {
    $build = static function (string $dir): array {
        $router = new Router();
        $router->get('/', static fn (): string => 'ok')->name('home');
        $router->get('/users/{id:int}', static fn (int $id): string => (string) $id)->name('users.show');
        $router->get('/api/v1/posts/{slug:slug}', static fn (string $slug): string => $slug)->name('posts.show');
        $router->get('/api/v1/tags/{tag:alpha}', static fn (string $tag): string => $tag)->name('tags.show');

        return new Compiler()->compileToDirectory(
            $router->getCollection(),
            $dir,
            new CompilerOptions(optimizationLevel: 2),
        );
    };

    $dir1 = sys_get_temp_dir() . '/routir_golden_1_' . uniqid('', true);
    $dir2 = sys_get_temp_dir() . '/routir_golden_2_' . uniqid('', true);

    try {
        $res1 = $build($dir1);
        $res2 = $build($dir2);

        expect($res1['buildId'])->toBe($res2['buildId'])
            ->and(file_get_contents($dir1 . '/current/matcher.php'))->toBe(file_get_contents($dir2 . '/current/matcher.php'))
            ->and(file_get_contents($dir1 . '/current/manifest.json'))->toBe(file_get_contents($dir2 . '/current/manifest.json'));
    } finally {
        exec('rm -rf ' . escapeshellarg($dir1) . ' ' . escapeshellarg($dir2));
    }
});

test('O2 specializes small exact-route tries by segment count', function (): void {
    $router = new Router();
    $router->get('/users/{id:int}', static fn (int $id): string => (string) $id)->name('users.show');
    $router->get('/api/v1/posts/{slug:slug}', static fn (string $slug): string => $slug)->name('posts.show');

    $dir = sys_get_temp_dir() . '/routir_specialized_' . uniqid('', true);

    try {
        new Compiler()->compileToDirectory(
            collection: $router->getCollection(),
            outputDir: $dir,
            options: new CompilerOptions(optimizationLevel: 2),
        );

        $code = file_get_contents($dir . '/current/matcher.php');

        expect($code)->toBeString();
        assert(is_string($code));

        expect($code)
            ->toContain('switch ($segCount)')
            ->toContain('case 2: goto sc2_')
            ->toContain('case 4: goto sc4_')
            ->and(str_contains($code, 'if (!isset($segments['))->toBeFalse();
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
});
