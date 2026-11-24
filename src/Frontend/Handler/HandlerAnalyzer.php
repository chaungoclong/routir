<?php

declare(strict_types=1);

namespace Routir\Frontend\Handler;

use Closure;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Routir\Diagnostics\Diagnostic;
use Routir\Diagnostics\Severity;
use Routir\Diagnostics\SourceSpan;
use Routir\Exception\SemanticException;
use Routir\Semantic\NormalizedRoute;

final class HandlerAnalyzer
{
    /**
     * Analyze a normalized route's handler and extract parameter binding metadata.
     *
     * @param NormalizedRoute $route Normalized route definition.
     *
     * @return HandlerPlan
     *
     * @throws SemanticException When the handler is invalid or any parameter signature cannot be bound.
     * @throws ReflectionException When reflecting the handler fails.
     */
    public function analyze(NormalizedRoute $route): HandlerPlan
    {
        $handler = $route->handler;
        $span = new SourceSpan(0, strlen($route->pattern), $route->file, $route->line);

        $routeParamNames = [];
        foreach ($route->segments as $seg) {
            if ($seg->kind === 'param' || $seg->kind === 'wildcard') {
                $routeParamNames[$seg->value] = true;
            }
        }

        if ($handler instanceof Closure) {
            $ref = new ReflectionFunction($handler);
            $params = $this->analyzeParameters($ref->getParameters(), $route, $routeParamNames, $span);

            return new HandlerPlan(
                kind: 'closure',
                parameters: $params,
                closure: $handler,
                file: $ref->getFileName() ?: $route->file,
                line: $ref->getStartLine() ?: $route->line,
            );
        }

        if (is_array($handler) && count($handler) === 2 && isset($handler[0], $handler[1]) && is_string($handler[1])) {
            $target = $handler[0];
            $method = $handler[1];

            if (is_string($target)) {
                if (!class_exists($target) || !method_exists($target, $method)) {
                    throw SemanticException::fromDiagnostic(new Diagnostic(
                        code: 'RC_HANDLER_NOT_FOUND',
                        severity: Severity::Error,
                        message: sprintf('Controller handler %s::%s does not exist.', $target, $method),
                        span: $span,
                    ));
                }
                $ref = new ReflectionMethod($target, $method);
                $params = $this->analyzeParameters($ref->getParameters(), $route, $routeParamNames, $span);

                return new HandlerPlan(
                    kind: 'controller',
                    className: $target,
                    methodName: $method,
                    parameters: $params,
                    file: $route->file,
                    line: $route->line,
                );
            }

            if (is_object($target)) {
                if (!method_exists($target, $method)) {
                    throw SemanticException::fromDiagnostic(new Diagnostic(
                        code: 'RC_HANDLER_NOT_FOUND',
                        severity: Severity::Error,
                        message: sprintf('Handler method %s::%s does not exist.', $target::class, $method),
                        span: $span,
                    ));
                }
                $ref = new ReflectionMethod($target, $method);
                $params = $this->analyzeParameters($ref->getParameters(), $route, $routeParamNames, $span);

                return new HandlerPlan(
                    kind: 'object_method',
                    className: $target::class,
                    methodName: $method,
                    parameters: $params,
                    instance: $target,
                    file: $route->file,
                    line: $route->line,
                );
            }
        }

        if (is_string($handler) && class_exists($handler)) {
            if (!method_exists($handler, '__invoke')) {
                throw SemanticException::fromDiagnostic(new Diagnostic(
                    code: 'RC_HANDLER_NOT_INVOKABLE',
                    severity: Severity::Error,
                    message: sprintf('Invokable handler class %s must define __invoke().', $handler),
                    span: $span,
                ));
            }
            $ref = new ReflectionMethod($handler, '__invoke');
            $params = $this->analyzeParameters($ref->getParameters(), $route, $routeParamNames, $span);

            return new HandlerPlan(
                kind: 'invokable',
                className: $handler,
                methodName: '__invoke',
                parameters: $params,
                file: $route->file,
                line: $route->line,
            );
        }

        if (is_object($handler) && method_exists($handler, '__invoke')) {
            $ref = new ReflectionMethod($handler, '__invoke');
            $params = $this->analyzeParameters($ref->getParameters(), $route, $routeParamNames, $span);

            return new HandlerPlan(
                kind: 'object_method',
                className: $handler::class,
                methodName: '__invoke',
                parameters: $params,
                instance: $handler,
                file: $route->file,
                line: $route->line,
            );
        }

        throw SemanticException::fromDiagnostic(new Diagnostic(
            code: 'RC_HANDLER_INVALID',
            severity: Severity::Error,
            message: sprintf('Unsupported route handler of type "%s" for route "%s".', get_debug_type($handler), $route->pattern),
            span: $span,
            hint: 'Use a Closure, [ClassName::class, "method"], or invokable class.',
        ));
    }

    /**
     * Analyze reflected parameters and build binding plans according to Routir priority rules.
     *
     * @param list<ReflectionParameter> $refParams Reflected handler parameters.
     * @param array<string, bool> $routeParamNames Map of route parameter names.
     * @param SourceSpan $span Source span for diagnostics.
     *
     * @return list<ParameterPlan>
     *
     * @throws SemanticException When a parameter is by-ref, variadic, union/intersection, or unresolvable.
     */
    private function analyzeParameters(
        array $refParams,
        NormalizedRoute $route,
        array $routeParamNames,
        SourceSpan $span,
    ): array {
        $plans = [];

        foreach ($refParams as $rp) {
            $name = $rp->getName();

            if ($rp->isPassedByReference()) {
                throw SemanticException::fromDiagnostic(new Diagnostic(
                    code: 'RC_HANDLER_BYREF_PARAM',
                    severity: Severity::Error,
                    message: sprintf('Handler parameter "$%s" cannot be passed by reference.', $name),
                    span: $span,
                ));
            }

            if ($rp->isVariadic()) {
                throw SemanticException::fromDiagnostic(new Diagnostic(
                    code: 'RC_HANDLER_VARIADIC_PARAM',
                    severity: Severity::Error,
                    message: sprintf('Handler parameter "$%s" cannot be variadic.', $name),
                    span: $span,
                ));
            }

            $type = $rp->getType();
            if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
                throw SemanticException::fromDiagnostic(new Diagnostic(
                    code: 'RC_HANDLER_AMBIGUOUS_TYPE',
                    severity: Severity::Error,
                    message: sprintf('Handler parameter "$%s" has unsupported union or intersection type.', $name),
                    span: $span,
                ));
            }

            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            $allowsNull = $type === null || $type->allowsNull();
            $hasDefault = $rp->isDefaultValueAvailable();
            $defaultValue = $hasDefault ? $rp->getDefaultValue() : null;

            // Priority: explicit binding > named route capture > request > class/interface service > default > error
            if (array_key_exists($name, $route->bindings)) {
                $plans[] = new ParameterPlan(
                    name: $name,
                    source: 'binding',
                    typeName: $typeName,
                    allowsNull: $allowsNull,
                    hasDefault: $hasDefault,
                    defaultValue: $defaultValue,
                    bindingValue: $route->bindings[$name],
                );
                continue;
            }

            if (isset($routeParamNames[$name])) {
                $plans[] = new ParameterPlan(
                    name: $name,
                    source: 'route_param',
                    typeName: $typeName,
                    allowsNull: $allowsNull,
                    hasDefault: $hasDefault,
                    defaultValue: $defaultValue,
                );
                continue;
            }

            if ($typeName !== null && ($typeName === ServerRequestInterface::class || is_subclass_of($typeName, ServerRequestInterface::class))) {
                $plans[] = new ParameterPlan(
                    name: $name,
                    source: 'request',
                    typeName: $typeName,
                    allowsNull: $allowsNull,
                    hasDefault: $hasDefault,
                    defaultValue: $defaultValue,
                );
                continue;
            }

            if ($typeName !== null && !$type->isBuiltin() && (class_exists($typeName) || interface_exists($typeName))) {
                $plans[] = new ParameterPlan(
                    name: $name,
                    source: 'service',
                    typeName: $typeName,
                    allowsNull: $allowsNull,
                    hasDefault: $hasDefault,
                    defaultValue: $defaultValue,
                );
                continue;
            }

            if ($hasDefault) {
                $plans[] = new ParameterPlan(
                    name: $name,
                    source: 'default',
                    typeName: $typeName,
                    allowsNull: $allowsNull,
                    hasDefault: true,
                    defaultValue: $defaultValue,
                );
                continue;
            }

            throw SemanticException::fromDiagnostic(new Diagnostic(
                code: 'RC_HANDLER_UNRESOLVABLE_PARAM',
                severity: Severity::Error,
                message: sprintf('Cannot resolve handler parameter "$%s" on route "%s".', $name, $route->pattern),
                span: $span,
                hint: 'Bind the parameter to a route segment, typehint a service/ServerRequestInterface, or provide a default value.',
            ));
        }

        return $plans;
    }
}
