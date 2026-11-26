<?php

declare(strict_types=1);

namespace Routir\Backend\PHP;

use Routir\IR\HIR\HIRModule;

final class PHPUrlGeneratorEmitter
{
    private const int KIND_LITERAL = 0;
    private const int KIND_PARAM = 1;
    private const int KIND_WILDCARD = 2;

    private const int FLAG_OPTIONAL = 4;

    private const int CONSTRAINT_NONE = 0;
    private const int CONSTRAINT_BUILTIN = 1;
    private const int CONSTRAINT_IN = 2;
    private const int CONSTRAINT_REGEX = 3;

    /** @var array<string, int> */
    private const array BUILTIN_IDS = [
        'int' => 0,
        'alpha' => 1,
        'alnum' => 2,
        'slug' => 3,
        'uuid' => 4,
    ];

    /**
     * Emit url-generator.php with bit-packed segment flags and pre-encoded merged literals.
     *
     * @param HIRModule $hir HIR module.
     *
     * @return string Generated PHP code.
     */
    public function emit(HIRModule $hir): string
    {
        /** @var array<string, list<array{0: int, 1: string}|array{0: int, 1: string, 2: string|list<string>}>> $routesData */
        $routesData = [];

        foreach ($hir->namedRoutes as $name => $route) {
            /** @var list<array{0: int, 1: string}|array{0: int, 1: string, 2: string|list<string>}> $packed */
            $packed = [];
            $pendingLiteral = null;

            foreach ($route->segments as $seg) {
                if ($seg->kind === 'literal') {
                    $encoded = rawurlencode($seg->value);
                    $pendingLiteral = $pendingLiteral === null ? $encoded : $pendingLiteral . '/' . $encoded;
                    continue;
                }

                if ($pendingLiteral !== null) {
                    $packed[] = [self::KIND_LITERAL, $pendingLiteral];
                    $pendingLiteral = null;
                }

                $kindBits = $seg->kind === 'wildcard' ? self::KIND_WILDCARD : self::KIND_PARAM;
                $optBit = $seg->optional ? self::FLAG_OPTIONAL : 0;

                $cKind = match ($seg->constraint->kind) {
                    'builtin' => self::CONSTRAINT_BUILTIN,
                    'in' => self::CONSTRAINT_IN,
                    'regex' => self::CONSTRAINT_REGEX,
                    default => self::CONSTRAINT_NONE,
                };

                $builtinBits = 0;
                $extra = null;
                if ($cKind === self::CONSTRAINT_BUILTIN) {
                    $bName = $seg->constraint->builtinName ?? '';
                    if (isset(self::BUILTIN_IDS[$bName])) {
                        $builtinBits = self::BUILTIN_IDS[$bName] << 5;
                    } else {
                        $builtinBits = 5 << 5;
                        $extra = $bName;
                    }
                } elseif ($cKind === self::CONSTRAINT_IN) {
                    $extra = $seg->constraint->allowedValues;
                } elseif ($cKind === self::CONSTRAINT_REGEX) {
                    $extra = $seg->constraint->compiledRegex ?? '';
                }

                $flags = $kindBits | $optBit | ($cKind << 3) | $builtinBits;
                $packed[] = $extra === null
                    ? [$flags, $seg->value]
                    : [$flags, $seg->value, $extra];
            }

            if ($pendingLiteral !== null) {
                $packed[] = [self::KIND_LITERAL, $pendingLiteral];
            }

            $routesData[$name] = $packed;
        }

        $export = CompactPhpExporter::exportTopLevelMap($routesData);

        return <<<PHP
<?php

declare(strict_types=1);

use Routir\\Exception\\RouteNotFoundException;
use Routir\\Exception\\UrlGenerationException;
use Routir\\Runtime\\ConstraintRegistry;

return new class {
    /** @var array<string, list<array{0: int, 1: string, 2?: string|list<string>}>> */
    private const array ROUTES = {$export};

    public function has(string \$name): bool
    {
        return isset(self::ROUTES[\$name]);
    }

    /**
     * @param array<string, mixed> \$params
     */
    public function generate(string \$name, array \$params = []): string
    {
        if (!isset(self::ROUTES[\$name])) {
            throw new RouteNotFoundException(sprintf('Route "%s" is not defined.', \$name));
        }

        \$segments = self::ROUTES[\$name];
        \$usedParams = [];
        \$builtSegments = [];
        \$omittedOptional = false;

        foreach (\$segments as \$seg) {
            \$flags = \$seg[0];
            \$kind = \$flags & 3;
            if (\$kind === 0) {
                \$builtSegments[] = \$seg[1];
                continue;
            }

            \$paramName = \$seg[1];
            \$hasValue = array_key_exists(\$paramName, \$params) && \$params[\$paramName] !== null;

            if (\$kind === 2) {
                if (!\$hasValue) {
                    throw new UrlGenerationException(sprintf('Missing required wildcard parameter "%s" for route "%s".', \$paramName, \$name));
                }
                \$usedParams[\$paramName] = true;
                \$rawVal = \$this->stringifyValue(\$params[\$paramName], \$paramName, \$name);
                if (\$rawVal === '') {
                    throw new UrlGenerationException(sprintf('Wildcard parameter "%s" cannot be empty for route "%s".', \$paramName, \$name));
                }
                \$parts = explode('/', \$rawVal);
                \$encodedParts = [];
                foreach (\$parts as \$part) {
                    if (\$part === '' || \$part === '.' || \$part === '..') {
                        throw new UrlGenerationException(sprintf('Invalid wildcard segment in parameter "%s" for route "%s".', \$paramName, \$name));
                    }
                    \$encodedParts[] = rawurlencode(\$part);
                }
                \$builtSegments[] = implode('/', \$encodedParts);
                continue;
            }

            if (!\$hasValue) {
                if ((\$flags & 4) === 0) {
                    throw new UrlGenerationException(sprintf('Missing required parameter "%s" for route "%s".', \$paramName, \$name));
                }
                \$omittedOptional = true;
                continue;
            }

            if (\$omittedOptional) {
                throw new UrlGenerationException(sprintf('Cannot provide optional parameter "%s" after omitting an earlier optional parameter in route "%s".', \$paramName, \$name));
            }

            \$usedParams[\$paramName] = true;
            \$strVal = \$this->stringifyValue(\$params[\$paramName], \$paramName, \$name);

            if (\$strVal === '' || str_contains(\$strVal, '/')) {
                throw new UrlGenerationException(sprintf('Parameter "%s" cannot be empty or contain "/" for route "%s".', \$paramName, \$name));
            }

            \$cKind = (\$flags >> 3) & 3;
            \$matched = match (\$cKind) {
                0 => true,
                1 => match (\$flags >> 5) {
                    0 => !isset(\$strVal[4096]) && ctype_digit(\$strVal),
                    1 => !isset(\$strVal[4096]) && ctype_alpha(\$strVal),
                    2 => !isset(\$strVal[4096]) && ctype_alnum(\$strVal),
                    3 => !isset(\$strVal[4096]) && preg_match('/^[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*$/', \$strVal) === 1,
                    4 => strlen(\$strVal) === 36 && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', \$strVal) === 1,
                    default => ConstraintRegistry::matchesBuiltin(is_string(\$seg[2] ?? null) ? \$seg[2] : '', \$strVal),
                },
                2 => is_array(\$seg[2] ?? null) && ConstraintRegistry::matchesIn(\$seg[2], \$strVal),
                3 => ConstraintRegistry::matchesRegex(is_string(\$seg[2] ?? null) ? \$seg[2] : '', \$strVal),
                default => false,
            };

            if (!\$matched) {
                throw new UrlGenerationException(sprintf('Parameter "%s" value "%s" fails constraint for route "%s".', \$paramName, \$strVal, \$name));
            }

            \$builtSegments[] = rawurlencode(\$strVal);
        }

        \$path = \$builtSegments === [] ? '/' : '/' . implode('/', \$builtSegments);

        \$extra = [];
        foreach (\$params as \$k => \$v) {
            if (!isset(\$usedParams[\$k]) && \$v !== null) {
                \$extra[\$k] = \$v;
            }
        }

        if (\$extra !== []) {
            \$path .= '?' . http_build_query(\$extra, '', '&', PHP_QUERY_RFC3986);
        }

        return \$path;
    }

    private function stringifyValue(mixed \$value, string \$paramName, string \$routeName): string
    {
        if (is_string(\$value) || is_int(\$value) || is_float(\$value)) {
            return (string) \$value;
        }
        if (\$value instanceof \\Stringable) {
            return (string) \$value;
        }

        throw new UrlGenerationException(sprintf('Parameter "%s" for route "%s" must be scalar or Stringable.', \$paramName, \$routeName));
    }
};

PHP;
    }
}
