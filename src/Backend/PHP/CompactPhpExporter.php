<?php

declare(strict_types=1);

namespace Routir\Backend\PHP;

/**
 * Emits compact, deterministic single-line PHP literal expressions for compiled artifacts.
 */
final class CompactPhpExporter
{
    /**
     * Export an arbitrary scalar or nested array value as a compact PHP expression.
     *
     * @param mixed $value Scalar, null, or nested array to export.
     *
     * @return string Compact PHP literal representation.
     */
    public static function export(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $parts = [];
            if (array_is_list($value)) {
                foreach ($value as $item) {
                    $parts[] = self::export($item);
                }
            } else {
                foreach ($value as $k => $v) {
                    $parts[] = var_export($k, true) . '=>' . self::export($v);
                }
            }

            return '[' . implode(',', $parts) . ']';
        }

        if ($value === null) {
            return 'null';
        }

        return var_export($value, true);
    }

    /**
     * Export a map of top-level keys to values with one entry per line for readability and small diffs.
     *
     * @param array<int|string, mixed> $map Associative or indexed map.
     *
     * @return string Compact multi-line PHP array literal.
     */
    public static function exportTopLevelMap(array $map): string
    {
        if ($map === []) {
            return '[]';
        }

        $lines = ["[\n"];
        foreach ($map as $k => $v) {
            $lines[] = '        ' . var_export($k, true) . '=>' . self::export($v) . ",\n";
        }
        $lines[] = '    ]';

        return implode('', $lines);
    }
}
