<?php

declare(strict_types=1);

namespace Routir\Backend\PHP;

use Routir\IR\HIR\HIRModule;

final class PHPUrlGeneratorEmitter
{
    public function emit(HIRModule $hir): string
    {
        $routesData = [];
        foreach ($hir->namedRoutes as $name => $route) {
            $routesData[$name] = array_map(
                static fn ($seg): array => [
                    'kind' => $seg->kind,
                    'value' => $seg->value,
                    'optional' => $seg->optional,
                    'constraintKind' => $seg->constraint->kind,
                    'builtinName' => $seg->constraint->builtinName,
                    'allowedValues' => $seg->constraint->allowedValues,
                    'compiledRegex' => $seg->constraint->compiledRegex,
                ],
                $route->segments,
            );
        }

        $export = var_export($routesData, true);

        return <<<PHP
<?php

declare(strict_types=1);

use Routir\\Exception\\RouteNotFoundException;
use Routir\\Exception\\UrlGenerationException;
use Routir\\Runtime\\ConstraintRegistry;

return new class {
    /** @var array<string, list<array{kind: string, value: string, optional: bool, constraintKind: string, builtinName: ?string, allowedValues: list<string>, compiledRegex: ?string}>> */
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
            if (\$seg['kind'] === 'literal') {
                \$builtSegments[] = rawurlencode(\$seg['value']);
                continue;
            }

            \$paramName = \$seg['value'];
            \$hasValue = array_key_exists(\$paramName, \$params) && \$params[\$paramName] !== null;

            if (\$seg['kind'] === 'wildcard') {
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
                if (!\$seg['optional']) {
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

            \$matched = match (\$seg['constraintKind']) {
                'none' => true,
                'builtin' => ConstraintRegistry::matchesBuiltin(\$seg['builtinName'] ?? '', \$strVal),
                'in' => ConstraintRegistry::matchesIn(\$seg['allowedValues'], \$strVal),
                'regex' => ConstraintRegistry::matchesRegex(\$seg['compiledRegex'] ?? '', \$strVal),
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
