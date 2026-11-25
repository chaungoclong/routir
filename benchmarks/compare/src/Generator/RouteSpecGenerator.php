<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Generator;

use InvalidArgumentException;
use Routir\Benchmarks\Model\RouteSpec;
use Routir\Benchmarks\Model\RouteType;

/**
 * Generates deterministic, balanced route datasets and target paths for benchmarking.
 */
final class RouteSpecGenerator
{
    /**
     * Generate a balanced set of static, single-int-param, and multi-param routes.
     *
     * @param int $count Total number of routes to generate (must be >= 3).
     *
     * @return list<RouteSpec>
     *
     * @throws InvalidArgumentException When route count is less than 3.
     */
    public function generate(int $count): array
    {
        if ($count < 3) {
            throw new InvalidArgumentException('Route count must be at least 3.');
        }

        $specs = [];
        for ($i = 0; $i < $count; $i++) {
            $specs[] = match ($i % 3) {
                0 => new RouteSpec(
                    type: RouteType::StaticRoute,
                    routirPath: "/static/group{$i}/item",
                    standardPath: "/static/group{$i}/item",
                    wheres: [],
                    name: "s.{$i}",
                ),
                1 => new RouteSpec(
                    type: RouteType::IntParam,
                    routirPath: "/api/v1/resource{$i}/{id:int}",
                    standardPath: "/api/v1/resource{$i}/{id}",
                    wheres: ['id' => '[0-9]+'],
                    name: "d.{$i}",
                ),
                default => new RouteSpec(
                    type: RouteType::MultiParam,
                    routirPath: "/api/v1/org/{org:alpha}/repo{$i}/{slug:slug}",
                    standardPath: "/api/v1/org/{org}/repo{$i}/{slug}",
                    wheres: ['org' => '[a-zA-Z]+', 'slug' => '[a-zA-Z0-9_-]+'],
                    name: "m.{$i}",
                ),
            };
        }

        return $specs;
    }

    /**
     * Resolve the static, worst-case dynamic, and miss request targets for a route count.
     *
     * @param int $count Total number of generated routes.
     *
     * @return array{static: string, dynamic: string, miss: string, dynamicIndex: int}
     */
    public function resolveTargets(int $count): array
    {
        $lastMultiIdx = $count - 1;
        while ($lastMultiIdx > 0 && $lastMultiIdx % 3 !== 2) {
            $lastMultiIdx--;
        }

        return [
            'static' => '/static/group0/item',
            'dynamic' => "/api/v1/org/acme/repo{$lastMultiIdx}/hello-world",
            'miss' => '/not/found/anywhere',
            'dynamicIndex' => $lastMultiIdx,
        ];
    }
}
