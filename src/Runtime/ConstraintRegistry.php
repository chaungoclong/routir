<?php

declare(strict_types=1);

namespace Routir\Runtime;

use Routir\Exception\DispatchException;

final class ConstraintRegistry
{
    public const int MAX_SEGMENT_LENGTH = 4096;

    /** @var array<string, string> */
    public const array BUILTIN_PATTERNS = [
        'int' => '/^[0-9]+$/',
        'alpha' => '/^[a-zA-Z]+$/',
        'alnum' => '/^[a-zA-Z0-9]+$/',
        'slug' => '/^[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*$/',
        'uuid' => '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
    ];

    public static function isBuiltin(string $name): bool
    {
        return isset(self::BUILTIN_PATTERNS[$name]);
    }

    public static function compileRegex(string $pattern): string
    {
        // Escape '~' delimiter if not already escaped
        $escaped = preg_replace('/(?<!\\\\)~/', '\\~', $pattern);
        if ($escaped === null) {
            return '';
        }

        return '~^(?:' . $escaped . ')$~u';
    }

    public static function validateRegex(string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }
        $compiled = self::compileRegex($pattern);
        if ($compiled === '') {
            return false;
        }

        set_error_handler(static fn (): bool => true);
        try {
            $res = preg_match($compiled, '');
        } finally {
            restore_error_handler();
        }

        return $res !== false;
    }

    public static function matchesBuiltin(string $name, string $value): bool
    {
        if ($value === '' || strlen($value) > self::MAX_SEGMENT_LENGTH) {
            return false;
        }

        return match ($name) {
            'int' => ctype_digit($value),
            'alpha' => ctype_alpha($value),
            'alnum' => ctype_alnum($value),
            'slug' => preg_match(self::BUILTIN_PATTERNS['slug'], $value) === 1,
            'uuid' => preg_match(self::BUILTIN_PATTERNS['uuid'], $value) === 1,
            default => false,
        };
    }

    /**
     * @param list<string> $allowed
     */
    public static function matchesIn(array $allowed, string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return in_array($value, $allowed, true);
    }

    /**
     * Match a segment value against a compiled custom PCRE regex with bounded length.
     *
     * @param string $compiledRegex Anchored PCRE pattern.
     * @param string $value Decoded segment value.
     *
     * @return bool
     *
     * @throws DispatchException When segment length exceeds limits or the PCRE engine errors.
     */
    public static function matchesRegex(string $compiledRegex, string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (strlen($value) > self::MAX_SEGMENT_LENGTH) {
            throw new DispatchException('Path segment exceeds maximum allowed length for regex constraint.');
        }

        $res = @preg_match($compiledRegex, $value);
        if ($res === false) {
            $err = preg_last_error_msg();
            throw new DispatchException(sprintf('PCRE regex engine error: %s', $err));
        }

        return $res === 1;
    }
}
