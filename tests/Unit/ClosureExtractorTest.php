<?php

declare(strict_types=1);

use Routir\Exception\CompilationException;
use Routir\Frontend\Handler\ClosureExtractor;

final class ClosureBoundHelper
{
    public function make(): Closure
    {
        return function (): string {
            return $this->name();
        };
    }

    private function name(): string
    {
        return 'ok';
    }
}

test('extracts stateless arrow and closure with magic constants', function (): void {
    $extractor = new ClosureExtractor();
    $fn1 = static fn (string $name): string => 'hello ' . $name;
    $fn2 = static function (): array {
        return [basename(__FILE__), __LINE__];
    };

    $ext1 = $extractor->extract($fn1);
    $ext2 = $extractor->extract($fn2);

    $rehydrated1 = eval('return ' . $ext1->factoryCode . ';');
    expect($rehydrated1)->toBeInstanceOf(Closure::class)
        ->and($rehydrated1('world'))->toBe('hello world');

    $rehydrated2 = eval('return ' . $ext2->factoryCode . ';');
    expect($rehydrated2)->toBeInstanceOf(Closure::class);
    $res2 = $rehydrated2();
    expect($res2[0])->toBe('ClosureExtractorTest.php')
        ->and($res2[1])->toBeInt();
});

test('extracts scalar and array captures and runs in fresh process', function (): void {
    $prefix = 'v1';
    $meta = ['env' => 'prod', 'nums' => [1, 2, 3]];
    $fn = static function (string $name) use ($prefix, $meta): string {
        return $prefix . ':' . $meta['env'] . ':' . $name;
    };

    $ext = new ClosureExtractor()->extract($fn);
    expect($ext->scalarCaptures)->toBe(['prefix' => 'v1', 'meta' => $meta]);

    $tmp = sys_get_temp_dir() . '/routir_closure_test_' . uniqid('', true) . '.php';
    file_put_contents($tmp, "<?php\n\$fn = " . $ext->factoryCode . ";\necho \$fn('ok');\n");
    try {
        $out = [];
        $code = 0;
        exec('php ' . escapeshellarg($tmp), $out, $code);
        expect($code)->toBe(0)
            ->and(implode("\n", $out))->toBe('v1:prod:ok');
    } finally {
        @unlink($tmp);
    }
});

test('rejects object capture', function (): void {
    $obj = new stdClass();
    $fn = static fn (): object => $obj;

    expect(static fn () => new ClosureExtractor()->extract($fn))->toThrow(CompilationException::class);
});

test('rejects by-ref capture', function (): void {
    $x = 1;
    $fn = static function () use (&$x): int {
        return $x;
    };

    expect(static fn () => new ClosureExtractor()->extract($fn))->toThrow(CompilationException::class);
});

test('rejects bound this', function (): void {
    $fn = new ClosureBoundHelper()->make();

    expect(static fn () => new ClosureExtractor()->extract($fn))->toThrow(CompilationException::class);
});

test('rejects static local', function (): void {
    $fn = static function (): int {
        static $count = 0;

        return ++$count;
    };

    expect(static fn () => new ClosureExtractor()->extract($fn))->toThrow(CompilationException::class);
});

test('rejects same-line ambiguity', function (): void {
    $a = static fn (): int => 1; $b = static fn (): int => 2;

    expect(static fn () => new ClosureExtractor()->extract($a))->toThrow(CompilationException::class);
});
