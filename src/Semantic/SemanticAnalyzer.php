<?php

declare(strict_types=1);

namespace Routir\Semantic;

use Routir\API\RouteCollection;
use Routir\API\RouteDefinition;
use Routir\Diagnostics\Diagnostic;
use Routir\Diagnostics\DiagnosticBag;
use Routir\Diagnostics\Severity;
use Routir\Diagnostics\SourceSpan;
use Routir\Exception\ParseException;
use Routir\Exception\SemanticException;
use Routir\Frontend\AST\LiteralNode;
use Routir\Frontend\AST\ParameterNode;
use Routir\Frontend\Parser\Parser;
use Routir\Runtime\ConstraintRegistry;

final class SemanticAnalyzer
{
    public function __construct(
        private readonly Parser $parser = new Parser(),
    ) {
    }

    /**
     * Validate and normalize all route definitions in a RouteCollection.
     *
     * @param RouteCollection $collection Collected route definitions.
     *
     * @return SemanticResult
     *
     * @throws SemanticException When pattern parsing, constraints, names, signatures, or middleware aliases are invalid.
     */
    public function analyze(RouteCollection $collection): SemanticResult
    {
        $diagnostics = new DiagnosticBag();
        /** @var list<NormalizedRoute> $routes */
        $routes = [];
        /** @var array<string, NormalizedRoute> $namedRoutes */
        $namedRoutes = [];
        /** @var array<string, int> $seenSignatures */
        $seenSignatures = [];

        $aliases = $collection->getMiddlewareAliases();
        $priorities = $collection->getMiddlewarePriorities();

        $nextId = 0;
        foreach ($collection->all() as $def) {
            $route = $this->analyzeRoute($nextId++, $def, $aliases, $priorities, $diagnostics);
            if ($route === null) {
                continue;
            }

            if ($route->name !== null) {
                if (isset($namedRoutes[$route->name])) {
                    $diagnostics->add(new Diagnostic(
                        code: 'RC_SEM_DUPLICATE_NAME',
                        severity: Severity::Error,
                        message: sprintf('Duplicate route name "%s".', $route->name),
                        span: new SourceSpan(0, strlen($def->pattern), $def->file, $def->line),
                        offendingText: $route->name,
                        hint: 'Route names must be globally unique.',
                    ));
                } else {
                    $namedRoutes[$route->name] = $route;
                }
            }

            foreach ($route->methods as $method) {
                $sigKey = $method . ' ' . $route->signature;
                if (isset($seenSignatures[$sigKey])) {
                    $diagnostics->add(new Diagnostic(
                        code: 'RC_SEM_DUPLICATE_ROUTE',
                        severity: Severity::Error,
                        message: sprintf('Duplicate route signature "%s %s".', $method, $route->pattern),
                        span: new SourceSpan(0, strlen($def->pattern), $def->file, $def->line),
                        offendingText: $route->pattern,
                        hint: 'Each method + normalized path + constraint signature must be unique.',
                    ));
                } else {
                    $seenSignatures[$sigKey] = $route->id;
                }
            }

            $routes[] = $route;
        }

        $this->detectOverlaps($routes, $diagnostics);

        if ($diagnostics->hasErrors()) {
            throw new SemanticException($diagnostics);
        }

        return new SemanticResult(
            routes: $routes,
            namedRoutes: $namedRoutes,
            fallbackHandler: $collection->getFallbackHandler(),
            fallbackFile: $collection->getFallbackFile(),
            fallbackLine: $collection->getFallbackLine(),
            diagnostics: $diagnostics,
        );
    }

    /**
     * @param array<string, string|list<string>> $aliases
     * @param array<string, int> $priorities
     */
    private function analyzeRoute(
        int $id,
        RouteDefinition $def,
        array $aliases,
        array $priorities,
        DiagnosticBag $diagnostics,
    ): ?NormalizedRoute {
        try {
            $ast = $this->parser->parse($def->pattern, $def->file, $def->line);
        } catch (ParseException $e) {
            $diagnostics->merge($e->diagnostics);

            return null;
        }

        /** @var list<NormalizedSegment> $segments */
        $segments = [];
        /** @var array<string, bool> $paramNames */
        $paramNames = [];
        $seenOptional = false;
        $totalSegments = count($ast->segments);

        foreach ($ast->segments as $idx => $segNode) {
            if ($segNode instanceof LiteralNode) {
                if ($seenOptional) {
                    $diagnostics->add(new Diagnostic(
                        code: 'RC_SEM_OPTIONAL_ORDER',
                        severity: Severity::Error,
                        message: sprintf('Literal segment "%s" cannot appear after an optional parameter.', $segNode->value),
                        span: $segNode->span,
                        offendingText: $segNode->value,
                        hint: 'Optional parameters are only allowed in a trailing contiguous suffix.',
                    ));
                }
                $segments[] = new NormalizedSegment(
                    kind: 'literal',
                    value: $segNode->value,
                    constraint: new NormalizedConstraint('none'),
                    optional: false,
                );
                continue;
            }

            if ($segNode instanceof ParameterNode) {
                if (isset($paramNames[$segNode->name])) {
                    $diagnostics->add(new Diagnostic(
                        code: 'RC_SEM_DUPLICATE_PARAM',
                        severity: Severity::Error,
                        message: sprintf('Duplicate parameter name "%s" in route "%s".', $segNode->name, $def->pattern),
                        span: $segNode->span,
                        offendingText: $segNode->name,
                        hint: 'Parameter names must be unique within a single route.',
                    ));
                }
                $paramNames[$segNode->name] = true;

                if ($segNode->wildcard) {
                    if ($idx !== $totalSegments - 1) {
                        $diagnostics->add(new Diagnostic(
                            code: 'RC_SEM_WILDCARD_POSITION',
                            severity: Severity::Error,
                            message: sprintf('Wildcard parameter "{%s:*}" must be the final segment.', $segNode->name),
                            span: $segNode->span,
                            offendingText: $segNode->name,
                            hint: 'Move the wildcard parameter to the end of the route.',
                        ));
                    }
                    if ($seenOptional) {
                        $diagnostics->add(new Diagnostic(
                            code: 'RC_SEM_OPTIONAL_ORDER',
                            severity: Severity::Error,
                            message: 'Wildcard parameter cannot follow an optional parameter.',
                            span: $segNode->span,
                            offendingText: $segNode->name,
                        ));
                    }
                    if (isset($def->wheres[$segNode->name])) {
                        $diagnostics->add(new Diagnostic(
                            code: 'RC_SEM_CONFLICTING_CONSTRAINT',
                            severity: Severity::Error,
                            message: sprintf('Wildcard parameter "%s" cannot also have a fluent where() constraint.', $segNode->name),
                            span: $segNode->span,
                            offendingText: $segNode->name,
                        ));
                    }
                    $segments[] = new NormalizedSegment(
                        kind: 'wildcard',
                        value: $segNode->name,
                        constraint: new NormalizedConstraint('wildcard'),
                        optional: false,
                    );
                    continue;
                }

                if ($segNode->optional) {
                    $seenOptional = true;
                } elseif ($seenOptional) {
                    $diagnostics->add(new Diagnostic(
                        code: 'RC_SEM_OPTIONAL_ORDER',
                        severity: Severity::Error,
                        message: sprintf('Required parameter "{%s}" cannot appear after an optional parameter.', $segNode->name),
                        span: $segNode->span,
                        offendingText: $segNode->name,
                        hint: 'Optional parameters are only allowed in a trailing contiguous suffix.',
                    ));
                }

                $constraint = $this->resolveConstraint($segNode, $def, $diagnostics);

                $segments[] = new NormalizedSegment(
                    kind: 'param',
                    value: $segNode->name,
                    constraint: $constraint,
                    optional: $segNode->optional,
                );
            }
        }

        $middleware = $this->flattenMiddleware(
            $def->middleware,
            $def->withoutMiddleware,
            $aliases,
            $priorities,
            new SourceSpan(0, strlen($def->pattern), $def->file, $def->line),
            $diagnostics,
        );

        $signature = $this->buildSignature($segments);

        return new NormalizedRoute(
            id: $id,
            registrationOrder: $def->registrationOrder,
            methods: $def->methods,
            pattern: $def->pattern,
            segments: $segments,
            name: $def->name,
            handler: $def->handler,
            middleware: $middleware,
            defaults: $def->defaults,
            bindings: $def->bindings,
            signature: $signature,
            file: $def->file,
            line: $def->line,
        );
    }

    private function resolveConstraint(
        ParameterNode $segNode,
        RouteDefinition $def,
        DiagnosticBag $diagnostics,
    ): NormalizedConstraint {
        $inline = $segNode->constraint;
        $fluent = $def->wheres[$segNode->name] ?? null;

        if ($inline !== null && !ConstraintRegistry::isBuiltin($inline)) {
            $diagnostics->add(new Diagnostic(
                code: 'RC_SEM_UNKNOWN_CONSTRAINT',
                severity: Severity::Error,
                message: sprintf('Unknown inline constraint "%s" on parameter "%s".', $inline, $segNode->name),
                span: $segNode->span,
                offendingText: $inline,
                hint: 'Built-in inline constraints are: int, alpha, alnum, slug, uuid, or "*". Use ->where() for custom regex.',
            ));

            return new NormalizedConstraint('none');
        }

        if ($inline !== null && $fluent !== null) {
            $fluentBuiltin = is_string($fluent) && str_starts_with($fluent, '__builtin:')
                ? substr($fluent, 10)
                : null;
            if ($fluentBuiltin !== $inline) {
                $diagnostics->add(new Diagnostic(
                    code: 'RC_SEM_CONFLICTING_CONSTRAINT',
                    severity: Severity::Error,
                    message: sprintf('Conflicting inline constraint ":%s" and fluent constraint on parameter "%s".', $inline, $segNode->name),
                    span: $segNode->span,
                    offendingText: $segNode->name,
                    hint: 'Specify the constraint either inline or via fluent where*(), not both.',
                ));
            }
        }

        if ($inline !== null) {
            return new NormalizedConstraint('builtin', builtinName: $inline);
        }

        if ($fluent === null) {
            return new NormalizedConstraint('none');
        }

        if (is_array($fluent)) {
            if ($fluent['values'] === []) {
                $diagnostics->add(new Diagnostic(
                    code: 'RC_SEM_INVALID_CONSTRAINT',
                    severity: Severity::Error,
                    message: sprintf('whereIn() for parameter "%s" must have at least one allowed value.', $segNode->name),
                    span: $segNode->span,
                    offendingText: $segNode->name,
                ));
            }

            return new NormalizedConstraint('in', allowedValues: $fluent['values']);
        }

        if (str_starts_with($fluent, '__builtin:')) {
            $name = substr($fluent, 10);

            return new NormalizedConstraint('builtin', builtinName: $name);
        }

        if (!ConstraintRegistry::validateRegex($fluent)) {
            $diagnostics->add(new Diagnostic(
                code: 'RC_SEM_INVALID_REGEX',
                severity: Severity::Error,
                message: sprintf('Invalid custom regex constraint "%s" for parameter "%s".', $fluent, $segNode->name),
                span: $segNode->span,
                offendingText: $fluent,
                hint: 'Provide a valid PCRE pattern without delimiters.',
            ));

            return new NormalizedConstraint('none');
        }

        return new NormalizedConstraint(
            kind: 'regex',
            compiledRegex: ConstraintRegistry::compileRegex($fluent),
            rawRegex: $fluent,
        );
    }

    /**
     * @param list<string> $middleware
     * @param list<string> $withoutMiddleware
     * @param array<string, string|list<string>> $aliases
     * @param array<string, int> $priorities
     * @return list<string>
     */
    private function flattenMiddleware(
        array $middleware,
        array $withoutMiddleware,
        array $aliases,
        array $priorities,
        SourceSpan $span,
        DiagnosticBag $diagnostics,
    ): array {
        $expanded = [];
        foreach ($middleware as $entry) {
            $this->expandMiddlewareEntry($entry, $aliases, [], $expanded, $span, $diagnostics);
        }

        if ($withoutMiddleware !== []) {
            $expandedExclusions = [];
            foreach ($withoutMiddleware as $ex) {
                $expandedExclusions[] = $ex;
                $this->expandMiddlewareEntry($ex, $aliases, [], $expandedExclusions, $span, $diagnostics);
            }
            $expandedExclusions = array_values(array_unique($expandedExclusions));

            $filtered = [];
            foreach ($expanded as $mw) {
                $excluded = false;
                foreach ($expandedExclusions as $ex) {
                    if ($this->middlewareMatchesIdentity($mw, $ex)) {
                        $excluded = true;
                        break;
                    }
                }
                if (!$excluded) {
                    $filtered[] = $mw;
                }
            }
            $expanded = $filtered;
        }

        if ($priorities !== [] && count($expanded) > 1) {
            // Stable sort by priority while preserving relative order for equal/unlisted items
            $indexed = [];
            foreach ($expanded as $idx => $mw) {
                $base = $this->baseMiddlewareName($mw);
                $prio = $priorities[$mw] ?? $priorities[$base] ?? PHP_INT_MAX;
                $indexed[] = ['mw' => $mw, 'prio' => $prio, 'idx' => $idx];
            }
            usort($indexed, static function (array $a, array $b): int {
                return ($a['prio'] <=> $b['prio']) ?: ($a['idx'] <=> $b['idx']);
            });
            $expanded = array_column($indexed, 'mw');
        }

        return $expanded;
    }

    /**
     * @param array<string, string|list<string>> $aliases
     * @param array<string, bool> $visiting
     * @param list<string> $out
     */
    private function expandMiddlewareEntry(
        string $entry,
        array $aliases,
        array $visiting,
        array &$out,
        SourceSpan $span,
        DiagnosticBag $diagnostics,
    ): void {
        $base = $this->baseMiddlewareName($entry);
        $params = str_contains($entry, ':') ? substr($entry, strlen($base)) : '';

        if (isset($aliases[$entry])) {
            $key = $entry;
            $suffix = '';
        } elseif (isset($aliases[$base])) {
            $key = $base;
            $suffix = $params;
        } else {
            $out[] = $entry;

            return;
        }

        if (isset($visiting[$key])) {
            $diagnostics->add(new Diagnostic(
                code: 'RC_SEM_MIDDLEWARE_CYCLE',
                severity: Severity::Error,
                message: sprintf('Cyclic middleware alias expansion detected at "%s".', $key),
                span: $span,
                offendingText: $key,
                hint: 'Remove circular references between middleware aliases/groups.',
            ));

            return;
        }

        $visiting[$key] = true;
        $target = $aliases[$key];
        $targets = is_array($target) ? $target : [$target];

        foreach ($targets as $t) {
            $resolved = $suffix !== '' && !str_contains($t, ':') ? $t . $suffix : $t;
            $this->expandMiddlewareEntry($resolved, $aliases, $visiting, $out, $span, $diagnostics);
        }
    }

    private function middlewareMatchesIdentity(string $mw, string $exclusion): bool
    {
        if ($mw === $exclusion) {
            return true;
        }

        return $this->baseMiddlewareName($mw) === $this->baseMiddlewareName($exclusion)
            && !str_contains($exclusion, ':');
    }

    private function baseMiddlewareName(string $entry): string
    {
        $pos = strpos($entry, ':');

        return $pos === false ? $entry : substr($entry, 0, $pos);
    }

    /**
     * @param list<NormalizedSegment> $segments
     */
    private function buildSignature(array $segments): string
    {
        if ($segments === []) {
            return '/';
        }

        $parts = array_map(
            static function (NormalizedSegment $seg): string {
                if ($seg->kind === 'literal') {
                    return 'L(' . $seg->value . ')';
                }
                if ($seg->kind === 'wildcard') {
                    return 'W(*)';
                }

                return 'P(' . $seg->constraint->signature() . ')' . ($seg->optional ? '?' : '');
            },
            $segments,
        );

        return '/' . implode('/', $parts);
    }

    /**
     * @param list<NormalizedRoute> $routes
     */
    private function detectOverlaps(array $routes, DiagnosticBag $diagnostics): void
    {
        $count = count($routes);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $routes[$i];
                $b = $routes[$j];

                if (array_intersect($a->methods, $b->methods) === []) {
                    continue;
                }

                if ($this->routesCanOverlap($a, $b)) {
                    $diagnostics->add(new Diagnostic(
                        code: 'RC_SEM_OVERLAPPING_ROUTES',
                        severity: Severity::Warning,
                        message: sprintf('Routes "%s" and "%s" may overlap.', $a->pattern, $b->pattern),
                        span: new SourceSpan(0, strlen($b->pattern), $b->file, $b->line),
                        offendingText: $b->pattern,
                        hint: 'Ensure route specificity and registration order match your intended precedence.',
                    ));
                }
            }
        }
    }

    private function routesCanOverlap(NormalizedRoute $a, NormalizedRoute $b): bool
    {
        if (count($a->segments) !== count($b->segments)) {
            return false;
        }
        $n = count($a->segments);
        if ($n === 0) {
            return false;
        }

        $allLiteral = true;
        for ($k = 0; $k < $n; $k++) {
            $sa = $a->segments[$k];
            $sb = $b->segments[$k];
            if ($sa->kind === 'literal' && $sb->kind === 'literal') {
                if ($sa->value !== $sb->value) {
                    return false;
                }
            } else {
                $allLiteral = false;
            }
        }

        return !$allLiteral;
    }
}
