<?php

declare(strict_types=1);

namespace Routir\API;

use Routir\Exception\RoutirException;

final class RouteLoader
{
    /**
     * Resolve one or more route files or directories into a deterministic list of PHP files.
     *
     * @param string|list<string> $paths File path(s) or directory path(s).
     *
     * @return list<string>
     *
     * @throws RoutirException When paths are empty, do not exist, or contain no PHP files.
     */
    public static function resolveFiles(string|array $paths): array
    {
        $list = is_string($paths) ? [$paths] : $paths;
        if ($list === []) {
            throw new RoutirException('At least one route input file or directory must be provided.');
        }

        $resolved = [];
        foreach ($list as $path) {
            if (is_file($path)) {
                $real = realpath($path);
                $resolved[] = $real !== false ? $real : $path;
                continue;
            }

            if (is_dir($path)) {
                $entries = glob(rtrim($path, '/') . '/*.php');
                if ($entries === false) {
                    $entries = [];
                }
                sort($entries, SORT_STRING);
                foreach ($entries as $entry) {
                    if (is_file($entry)) {
                        $real = realpath($entry);
                        $resolved[] = $real !== false ? $real : $entry;
                    }
                }
                continue;
            }

            throw new RoutirException(sprintf('Route input path "%s" does not exist.', $path));
        }

        if ($resolved === []) {
            throw new RoutirException('No route PHP files found in the configured input paths.');
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Load route files into the given Router instance.
     *
     * @param Router $router Target router instance.
     * @param string|list<string> $paths File path(s) or directory path(s).
     *
     * @return list<string> Resolved input file paths.
     *
     * @throws RoutirException When route files cannot be resolved.
     */
    public static function loadInto(Router $router, string|array $paths): array
    {
        $files = self::resolveFiles($paths);

        foreach ($files as $file) {
            $returned = (static function (Router $router, string $f): mixed {
                return require $f;
            })($router, $file);

            if (is_callable($returned)) {
                $returned($router);
            }
        }

        return $files;
    }
}
