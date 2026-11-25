<?php

declare(strict_types=1);

use Routir\Exception\InvalidRequestTargetException;
use Routir\Runtime\PathNormalizer;

test('normalizes root and strips query and fragment', function (): void {
    expect(PathNormalizer::normalize('/'))->toBe([])
        ->and(PathNormalizer::normalize('/?foo=bar#baz'))->toBe([])
        ->and(PathNormalizer::normalize('/users/42?x=1#top'))->toBe(['users', '42']);
});

test('decodes percent encoded segments after splitting', function (): void {
    expect(PathNormalizer::normalize('/hello%20world/caf%C3%A9'))->toBe(['hello world', 'café']);
});

test('preserves trailing slash as empty segment', function (): void {
    expect(PathNormalizer::normalize('/users/'))->toBe(['users', '']);
});

test('normalizes printable punctuation and valid unicode without changing segments', function (): void {
    expect(PathNormalizer::normalize('/symbols/@!$&\'()+,;=:'))
        ->toBe(['symbols', '@!$&\'()+,;=:'])
        ->and(PathNormalizer::normalize('/xin-chào/東京'))
        ->toBe(['xin-chào', '東京']);
});

test('rejects invalid request targets', function (string $target): void {
    expect(static fn () => PathNormalizer::normalize($target))
        ->toThrow(InvalidRequestTargetException::class);
/** @phpstan-ignore method.notFound */
})->with([
    'empty' => [''],
    'no leading slash' => ['users/1'],
    'malformed percent' => ['/users/%ZZ'],
    'incomplete percent' => ['/users/%2'],
    'encoded slash' => ['/users/a%2Fb'],
    'encoded backslash' => ['/users/a%5Cb'],
    'encoded nul' => ['/users/a%00b'],
    'raw control byte' => ["/users/a\x07b"],
    'dot segment' => ['/users/.'],
    'double dot segment' => ['/users/..'],
    'encoded dot segment' => ['/users/%2e%2e'],
    'invalid utf8' => ['/users/%FF%FE'],
]);
