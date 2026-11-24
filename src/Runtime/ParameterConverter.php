<?php

declare(strict_types=1);

namespace Routir\Runtime;

use Routir\Exception\DispatchException;

final class ParameterConverter
{
    /**
     * Explicitly convert a raw captured route parameter string to its declared scalar type.
     *
     * @param string $raw Raw captured segment string.
     * @param ?string $typeName Target PHP type name (int, float, bool, string, or null).
     * @param string $paramName Parameter name for error context.
     *
     * @return mixed
     *
     * @throws DispatchException When conversion or range validation fails.
     */
    public static function convertRouteParam(string $raw, ?string $typeName, string $paramName): mixed
    {
        if ($typeName === null || $typeName === 'string' || $typeName === 'mixed') {
            return $raw;
        }

        if ($typeName === 'int') {
            if (preg_match('/^-?[0-9]+$/', $raw) !== 1) {
                throw new DispatchException(sprintf('Cannot convert route parameter "%s" value "%s" to int.', $paramName, $raw));
            }
            $intVal = filter_var($raw, FILTER_VALIDATE_INT);
            if ($intVal === false) {
                throw new DispatchException(sprintf('Route parameter "%s" value "%s" is out of integer range.', $paramName, $raw));
            }

            return $intVal;
        }

        if ($typeName === 'float') {
            if (!is_numeric($raw)) {
                throw new DispatchException(sprintf('Cannot convert route parameter "%s" value "%s" to float.', $paramName, $raw));
            }

            return (float) $raw;
        }

        if ($typeName === 'bool') {
            return match (strtolower($raw)) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => throw new DispatchException(sprintf('Cannot convert route parameter "%s" value "%s" to bool.', $paramName, $raw)),
            };
        }

        throw new DispatchException(sprintf('Unsupported parameter conversion type "%s" for "%s".', $typeName, $paramName));
    }
}
