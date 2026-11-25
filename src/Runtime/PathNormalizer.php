<?php

declare(strict_types=1);

namespace Routir\Runtime;

use Routir\Exception\InvalidRequestTargetException;

final class PathNormalizer
{
    /**
     * Normalize an HTTP request target into decoded path segments.
     *
     * @return list<string>
     * @throws InvalidRequestTargetException
     */
    public static function normalize(string $requestTarget): array
    {
        // Strip query string and fragment if present
        $cut = strcspn($requestTarget, '?#');
        $path = $cut === strlen($requestTarget) ? $requestTarget : substr($requestTarget, 0, $cut);

        if ($path === '' || $path[0] !== '/') {
            throw new InvalidRequestTargetException('Request target path must start with "/".');
        }

        if ($path === '/') {
            return [];
        }

        // Fast-path: printable ASCII without percent-encoding or backslashes.
        if (ctype_print($path) && strpbrk($path, '%\\') === false) {
            $segments = explode('/', substr($path, 1));
            if (str_contains($path, '/.') && (in_array('.', $segments, true) || in_array('..', $segments, true))) {
                throw new InvalidRequestTargetException('Dot segments ("." or "..") are not allowed in request target.');
            }

            return $segments;
        }

        // Check raw path for control bytes before splitting
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidRequestTargetException('Request target contains control characters.');
        }

        // Split raw path at literal '/' BEFORE percent-decoding
        $rawSegments = explode('/', substr($path, 1));
        $decoded = [];

        foreach ($rawSegments as $raw) {
            // Validate percent-encoding syntax: every '%' must be followed by 2 hex digits
            if (str_contains($raw, '%')) {
                if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
                    throw new InvalidRequestTargetException('Malformed percent-encoding in request target.');
                }
            }

            $seg = rawurldecode($raw);

            // Reject decoded slash or backslash
            if (str_contains($seg, '/') || str_contains($seg, '\\')) {
                throw new InvalidRequestTargetException('Decoded slash or backslash is not allowed in path segment.');
            }

            // Reject NUL or control bytes after decoding
            if (preg_match('/[\x00-\x1F\x7F]/', $seg) === 1) {
                throw new InvalidRequestTargetException('Decoded control byte or NUL is not allowed in path segment.');
            }

            // Reject dot segments
            if ($seg === '.' || $seg === '..') {
                throw new InvalidRequestTargetException('Dot segments ("." or "..") are not allowed in request target.');
            }

            // Reject invalid UTF-8
            if (!mb_check_encoding($seg, 'UTF-8')) {
                throw new InvalidRequestTargetException('Invalid UTF-8 sequence in path segment.');
            }

            $decoded[] = $seg;
        }

        return $decoded;
    }
}
