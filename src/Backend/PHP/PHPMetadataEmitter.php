<?php

declare(strict_types=1);

namespace Routir\Backend\PHP;

use Routir\IR\HIR\HIRModule;

final class PHPMetadataEmitter
{
    public function emitMetadata(HIRModule $hir): string
    {
        $routes = [];
        foreach ($hir->routes as $route) {
            $routes[$route->id] = [
                'pattern' => $route->pattern,
                'methods' => $route->methods,
                'name' => $route->name,
                'hasMiddleware' => $route->middlewarePlan !== [],
            ];
        }

        $export = CompactPhpExporter::exportTopLevelMap($routes);

        return <<<PHP
<?php

declare(strict_types=1);

return {$export};

PHP;
    }

    /**
     * @return array<int, array{pattern: string, file: ?string, line: ?int}>
     */
    public function buildSourceMap(HIRModule $hir): array
    {
        $map = [];
        foreach ($hir->routes as $route) {
            $map[$route->id] = [
                'pattern' => $route->pattern,
                'file' => $route->file,
                'line' => $route->line,
            ];
        }

        return $map;
    }
}
