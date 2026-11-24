<?php

declare(strict_types=1);

namespace Routir\Backend\PHP;

use LogicException;
use ReflectionException;
use Routir\Diagnostics\Diagnostic;
use Routir\Diagnostics\Severity;
use Routir\Diagnostics\SourceSpan;
use Routir\Exception\CompilationException;
use Routir\Frontend\Handler\ClosureExtractor;
use Routir\Frontend\Handler\HandlerPlan;
use Routir\Frontend\Handler\ParameterPlan;
use Routir\IR\HIR\HIRModule;

final class PHPHandlerEmitter
{
    public function __construct(
        private readonly ClosureExtractor $closureExtractor = new ClosureExtractor(),
    ) {
    }

    /**
     * Emit handlers.php containing compiled handler invocation methods.
     *
     * @param HIRModule $hir HIR module.
     *
     * @return string Generated PHP code.
     *
     * @throws CompilationException When a handler or closure cannot be compiled.
     * @throws ReflectionException When closure reflection fails.
     * @throws LogicException When an unknown parameter source is encountered.
     */
    public function emit(HIRModule $hir): string
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\n";
        $code .= "use Psr\\Container\\ContainerInterface;\n";
        $code .= "use Psr\\Http\\Message\\ServerRequestInterface;\n";
        $code .= "use Routir\\Exception\\DispatchException;\n";
        $code .= "use Routir\\Runtime\\ParameterConverter;\n\n";
        $code .= "return new class {\n";
        $code .= "    /** @var array<int, \\Closure> */\n";
        $code .= "    private array \$closures = [];\n";
        $code .= "    private ?\\Closure \$fallbackClosure = null;\n\n";

        $code .= "    /**\n     * @param array<string, string> \$routeParams\n     */\n";
        $code .= "    public function invoke(int \$routeId, array \$routeParams, ServerRequestInterface \$request, ?ContainerInterface \$container): mixed\n    {\n";
        $code .= "        return match (\$routeId) {\n";
        foreach ($hir->routes as $route) {
            $code .= sprintf("            %d => \$this->invoke_%d(\$routeParams, \$request, \$container),\n", $route->id, $route->id);
        }
        $code .= "            default => throw new DispatchException('Unknown compiled route ID: ' . \$routeId),\n";
        $code .= "        };\n";
        $code .= "    }\n\n";

        $code .= "    public function hasFallback(): bool\n    {\n";
        $code .= '        return ' . ($hir->fallbackPlan !== null ? 'true' : 'false') . ";\n";
        $code .= "    }\n\n";

        $code .= "    public function invokeFallback(ServerRequestInterface \$request, ?ContainerInterface \$container): mixed\n    {\n";
        if ($hir->fallbackPlan !== null) {
            $code .= $this->emitInvokeBody(-1, $hir->fallbackPlan, true);
        } else {
            $code .= "        throw new DispatchException('No fallback handler compiled.');\n";
        }
        $code .= "    }\n\n";

        foreach ($hir->routes as $route) {
            $code .= "    /**\n     * @param array<string, string> \$routeParams\n     */\n";
            $code .= sprintf("    private function invoke_%d(array \$routeParams, ServerRequestInterface \$request, ?ContainerInterface \$container): mixed\n    {\n", $route->id);
            $code .= $this->emitInvokeBody($route->id, $route->handlerPlan, false);
            $code .= "    }\n\n";
        }

        $code .= <<<'PHP'
    private function resolveService(string $className, ?ContainerInterface $container, bool $hasDefault, mixed $default): object
    {
        if ($container !== null && $container->has($className)) {
            $service = $container->get($className);
            if (is_object($service)) {
                return $service;
            }
        }

        if (class_exists($className)) {
            $ref = new \ReflectionClass($className);
            $ctor = $ref->getConstructor();
            if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
                return new $className();
            }
        }

        if ($hasDefault && is_object($default)) {
            return $default;
        }

        throw new DispatchException(sprintf('Service "%s" could not be resolved from container.', $className));
    }
};
PHP;
        $code .= "\n";

        return $code;
    }

    /**
     * Emit the invocation body for a compiled route or fallback handler.
     *
     * @param int $routeId Route ID (-1 for fallback).
     * @param HandlerPlan $plan Handler plan metadata.
     * @param bool $isFallback Whether this is the fallback handler.
     *
     * @return string Generated PHP statements.
     *
     * @throws CompilationException When the handler or closure cannot be compiled.
     * @throws ReflectionException When closure reflection fails.
     * @throws LogicException When an unknown parameter source is encountered.
     */
    private function emitInvokeBody(int $routeId, HandlerPlan $plan, bool $isFallback): string
    {
        $body = '';
        $argExprs = array_map(
            fn (ParameterPlan $p): string => $this->emitParameterExpr($p, $isFallback),
            $plan->parameters,
        );
        $argsList = implode(', ', $argExprs);

        if ($plan->kind === 'closure' && $plan->closure !== null) {
            $extracted = $this->closureExtractor->extract($plan->closure);
            if ($isFallback) {
                $body .= "        \$fn = \$this->fallbackClosure ??= {$extracted->factoryCode};\n";
            } else {
                $body .= "        \$fn = \$this->closures[{$routeId}] ??= {$extracted->factoryCode};\n";
            }
            $body .= "        return \$fn({$argsList});\n";

            return $body;
        }

        if (($plan->kind === 'controller' || $plan->kind === 'invokable') && $plan->className !== null && $plan->methodName !== null) {
            $clsExport = var_export($plan->className, true);
            $method = $plan->methodName;
            $body .= "        \$instance = \$this->resolveService({$clsExport}, \$container, false, null);\n";
            $body .= "        return \$instance->{$method}({$argsList});\n";

            return $body;
        }

        throw CompilationException::fromDiagnostic(new Diagnostic(
            code: 'RC_HANDLER_UNCOMPILABLE',
            severity: Severity::Error,
            message: sprintf('Handler of kind "%s" (e.g. live object instance) cannot be AOT-compiled.', $plan->kind),
            span: new SourceSpan(0, 0, $plan->file, $plan->line),
            hint: 'Use a stateless/scalar-capturing Closure, [ClassName::class, "method"], or invokable class name.',
        ));
    }

    /**
     * Emit the PHP expression for resolving a single handler parameter at runtime.
     *
     * @param ParameterPlan $p Parameter plan.
     * @param bool $isFallback Whether emitting for the fallback handler.
     *
     * @return string PHP expression.
     *
     * @throws CompilationException When a binding or default value is not compilable.
     * @throws LogicException When an unknown parameter source is encountered.
     */
    private function emitParameterExpr(ParameterPlan $p, bool $isFallback): string
    {
        if ($p->source === 'binding') {
            if ($p->bindingValue !== null && !is_scalar($p->bindingValue) && !is_array($p->bindingValue)) {
                throw CompilationException::fromDiagnostic(new Diagnostic(
                    code: 'RC_HANDLER_UNCOMPILABLE_BINDING',
                    severity: Severity::Error,
                    message: sprintf('Explicit binding for parameter "$%s" must be scalar, null, or array in compiled mode.', $p->name),
                    span: new SourceSpan(0, 0),
                ));
            }

            return var_export($p->bindingValue, true);
        }

        if ($p->source === 'route_param') {
            if ($isFallback) {
                return $p->hasDefault ? var_export($p->defaultValue, true) : 'null';
            }
            $nameExp = var_export($p->name, true);
            $typeExp = var_export($p->typeName, true);
            $fallbackExpr = $p->hasDefault
                ? var_export($p->defaultValue, true)
                : ($p->allowsNull ? 'null' : "throw new DispatchException('Missing route parameter: ' . {$nameExp})");

            return "array_key_exists({$nameExp}, \$routeParams) ? ParameterConverter::convertRouteParam(\$routeParams[{$nameExp}], {$typeExp}, {$nameExp}) : ({$fallbackExpr})";
        }

        if ($p->source === 'request') {
            return '$request';
        }

        if ($p->source === 'service') {
            $clsExp = var_export($p->typeName ?? '', true);

            return "\$this->resolveService({$clsExp}, \$container, false, null)";
        }

        if ($p->source === 'default') {
            if ($p->defaultValue !== null && !is_scalar($p->defaultValue) && !is_array($p->defaultValue)) {
                throw CompilationException::fromDiagnostic(new Diagnostic(
                    code: 'RC_HANDLER_UNCOMPILABLE_DEFAULT',
                    severity: Severity::Error,
                    message: sprintf('Default value for parameter "$%s" must be scalar, null, or array in compiled mode.', $p->name),
                    span: new SourceSpan(0, 0),
                ));
            }

            return var_export($p->defaultValue, true);
        }

        throw new LogicException('Unknown parameter source: ' . $p->source);
    }
}
