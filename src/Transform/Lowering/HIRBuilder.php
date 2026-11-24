<?php

declare(strict_types=1);

namespace Routir\Transform\Lowering;

use ReflectionException;
use Routir\Exception\SemanticException;
use Routir\Frontend\Handler\HandlerAnalyzer;
use Routir\IR\HIR\HIRModule;
use Routir\IR\HIR\HIRRoute;
use Routir\Semantic\NormalizedRoute;
use Routir\Semantic\SemanticResult;

final class HIRBuilder
{
    public function __construct(
        private readonly HandlerAnalyzer $handlerAnalyzer = new HandlerAnalyzer(),
    ) {
    }

    /**
     * Build an HIRModule from a validated SemanticResult.
     *
     * @param SemanticResult $semantic Semantic analysis result.
     *
     * @return HIRModule
     *
     * @throws SemanticException When handler or parameter analysis fails.
     * @throws ReflectionException When handler reflection fails.
     */
    public function build(SemanticResult $semantic): HIRModule
    {
        $routes = [];
        $namedRoutes = [];

        foreach ($semantic->routes as $route) {
            $handlerPlan = $this->handlerAnalyzer->analyze($route);
            $hirRoute = new HIRRoute(
                id: $route->id,
                methods: $route->methods,
                pattern: $route->pattern,
                segments: $route->segments,
                handlerPlan: $handlerPlan,
                middlewarePlan: $route->middleware,
                name: $route->name,
                defaults: $route->defaults,
                registrationOrder: $route->registrationOrder,
                file: $route->file,
                line: $route->line,
            );
            $routes[] = $hirRoute;
            if ($hirRoute->name !== null) {
                $namedRoutes[$hirRoute->name] = $hirRoute;
            }
        }

        $fallbackPlan = null;
        if ($semantic->fallbackHandler !== null) {
            $dummyRoute = new NormalizedRoute(
                id: -1,
                registrationOrder: -1,
                methods: ['GET'],
                pattern: '/',
                segments: [],
                name: null,
                handler: $semantic->fallbackHandler,
                middleware: [],
                defaults: [],
                bindings: [],
                signature: '/',
                file: $semantic->fallbackFile,
                line: $semantic->fallbackLine,
            );
            $fallbackPlan = $this->handlerAnalyzer->analyze($dummyRoute);
        }

        return new HIRModule(
            routes: $routes,
            namedRoutes: $namedRoutes,
            fallbackPlan: $fallbackPlan,
        );
    }
}
