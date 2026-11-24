<?php

declare(strict_types=1);

namespace Routir\Runtime;

use Routir\Exception\DispatchException;
use Routir\Exception\RouteNotFoundException;
use Routir\Exception\UrlGenerationException;
use Routir\Semantic\NormalizedConstraint;
use Routir\Semantic\NormalizedRoute;
use Stringable;

final class UrlGenerator
{
    /**
     * @param array<string, NormalizedRoute> $namedRoutes
     */
    public function __construct(
        private readonly array $namedRoutes,
    ) {
    }

    public function has(string $name): bool
    {
        return isset($this->namedRoutes[$name]);
    }

    /**
     * Generate a relative URL path for a named route.
     *
     * @param string $name Registered route name.
     * @param array<string, mixed> $params Route and query parameters.
     *
     * @return string
     *
     * @throws RouteNotFoundException When the named route does not exist.
     * @throws UrlGenerationException When required parameters are missing, optional gaps occur, or constraints fail.
     * @throws DispatchException When a custom regex constraint encounters a PCRE engine error or length limit.
     */
    public function generate(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new RouteNotFoundException(sprintf('Route "%s" is not defined.', $name));
        }

        $route = $this->namedRoutes[$name];
        $usedParams = [];
        $builtSegments = [];
        $omittedOptional = false;

        foreach ($route->segments as $seg) {
            if ($seg->kind === 'literal') {
                $builtSegments[] = rawurlencode($seg->value);
                continue;
            }

            $paramName = $seg->value;
            $hasValue = array_key_exists($paramName, $params) && $params[$paramName] !== null;

            if ($seg->kind === 'wildcard') {
                if (!$hasValue) {
                    throw new UrlGenerationException(sprintf('Missing required wildcard parameter "%s" for route "%s".', $paramName, $name));
                }
                $usedParams[$paramName] = true;
                $rawVal = $this->stringifyValue($params[$paramName], $paramName, $name);
                if ($rawVal === '') {
                    throw new UrlGenerationException(sprintf('Wildcard parameter "%s" cannot be empty for route "%s".', $paramName, $name));
                }
                $parts = explode('/', $rawVal);
                $encodedParts = [];
                foreach ($parts as $part) {
                    if ($part === '' || $part === '.' || $part === '..') {
                        throw new UrlGenerationException(sprintf('Invalid wildcard segment in parameter "%s" for route "%s".', $paramName, $name));
                    }
                    $encodedParts[] = rawurlencode($part);
                }
                $builtSegments[] = implode('/', $encodedParts);
                continue;
            }

            // Regular parameter
            if (!$hasValue) {
                if (!$seg->optional) {
                    throw new UrlGenerationException(sprintf('Missing required parameter "%s" for route "%s".', $paramName, $name));
                }
                $omittedOptional = true;
                continue;
            }

            if ($omittedOptional) {
                throw new UrlGenerationException(sprintf('Cannot provide optional parameter "%s" after omitting an earlier optional parameter in route "%s".', $paramName, $name));
            }

            $usedParams[$paramName] = true;
            $strVal = $this->stringifyValue($params[$paramName], $paramName, $name);

            if ($strVal === '' || str_contains($strVal, '/')) {
                throw new UrlGenerationException(sprintf('Parameter "%s" cannot be empty or contain "/" for route "%s".', $paramName, $name));
            }

            if (!$this->matchesConstraint($seg->constraint, $strVal)) {
                throw new UrlGenerationException(sprintf('Parameter "%s" value "%s" fails constraint for route "%s".', $paramName, $strVal, $name));
            }

            $builtSegments[] = rawurlencode($strVal);
        }

        $path = $builtSegments === [] ? '/' : '/' . implode('/', $builtSegments);

        $extra = [];
        foreach ($params as $k => $v) {
            if (!isset($usedParams[$k]) && $v !== null) {
                $extra[$k] = $v;
            }
        }

        if ($extra !== []) {
            $path .= '?' . http_build_query($extra, '', '&', PHP_QUERY_RFC3986);
        }

        return $path;
    }

    /**
     * Convert a scalar or Stringable parameter value into a string.
     *
     * @param mixed $value Parameter value.
     * @param string $paramName Parameter name.
     * @param string $routeName Route name.
     *
     * @return string
     *
     * @throws UrlGenerationException When the value cannot be converted to a string.
     */
    private function stringifyValue(mixed $value, string $paramName, string $routeName): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new UrlGenerationException(sprintf('Parameter "%s" for route "%s" must be scalar or Stringable.', $paramName, $routeName));
    }

    /**
     * Check whether a parameter value satisfies the segment constraint.
     *
     * @param NormalizedConstraint $constraint Normalized parameter constraint.
     * @param string $value Stringified parameter value.
     *
     * @return bool
     *
     * @throws DispatchException When a custom regex constraint encounters a PCRE engine error or length limit.
     */
    private function matchesConstraint(NormalizedConstraint $constraint, string $value): bool
    {
        return match ($constraint->kind) {
            'none' => true,
            'builtin' => ConstraintRegistry::matchesBuiltin($constraint->builtinName ?? '', $value),
            'in' => ConstraintRegistry::matchesIn($constraint->allowedValues, $value),
            'regex' => ConstraintRegistry::matchesRegex($constraint->compiledRegex ?? '', $value),
            default => false,
        };
    }
}
