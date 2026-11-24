<?php

declare(strict_types=1);

namespace Routir\Backend\PHP;

use JsonException;
use Routir\IR\HIR\HIRModule;

final class PHPPipelineEmitter
{
    /**
     * Emit pipelines.php containing deduplicated middleware topology tables.
     *
     * @param HIRModule $hir HIR module.
     *
     * @return string Generated PHP code.
     *
     * @throws JsonException When middleware topology encoding fails.
     */
    public function emit(HIRModule $hir): string
    {
        /** @var array<string, int> $topologyIds */
        $topologyIds = [];
        /** @var list<list<string>> $topologies */
        $topologies = [];
        /** @var array<int, int> $routeToTopology */
        $routeToTopology = [];

        foreach ($hir->routes as $route) {
            $key = json_encode($route->middlewarePlan, JSON_THROW_ON_ERROR);
            if (!isset($topologyIds[$key])) {
                $topologyIds[$key] = count($topologies);
                $topologies[] = $route->middlewarePlan;
            }
            $routeToTopology[$route->id] = $topologyIds[$key];
        }

        $topExport = var_export($topologies, true);
        $mapExport = var_export($routeToTopology, true);

        return <<<PHP
<?php

declare(strict_types=1);

return [
    'topologies' => {$topExport},
    'routeToTopology' => {$mapExport},
];

PHP;
    }
}
