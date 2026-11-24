<?php

declare(strict_types=1);

namespace Routir\Transform\Lowering;

use Routir\IR\HIR\HIRModule;
use Routir\IR\HIR\HIRRoute;
use Routir\IR\MIR\MIRCandidate;
use Routir\IR\MIR\MIRModule;
use Routir\IR\MIR\MIRTrieEdge;
use Routir\IR\MIR\MIRTrieNode;

final class MIRLowering
{
    private int $nextNodeId = 0;

    public function lower(HIRModule $hir): MIRModule
    {
        /** @var array<int, HIRRoute> $routesById */
        $routesById = [];
        /** @var array<string, list<MIRCandidate>> $byMethod */
        $byMethod = [];

        foreach ($hir->routes as $route) {
            $routesById[$route->id] = $route;
            $total = count($route->segments);
            $required = 0;
            foreach ($route->segments as $seg) {
                if (!$seg->optional) {
                    $required++;
                }
            }

            for ($len = $total; $len >= $required; $len--) {
                $slice = array_slice($route->segments, 0, $len);
                $isStatic = true;
                $hasWildcard = false;
                foreach ($slice as $seg) {
                    if ($seg->kind !== 'literal') {
                        $isStatic = false;
                    }
                    if ($seg->kind === 'wildcard') {
                        $hasWildcard = true;
                    }
                }

                // Defaults apply ONLY to absent optional parameters
                $defaults = [];
                foreach ($route->segments as $idx => $seg) {
                    if ($idx >= $len && $seg->optional && isset($route->defaults[$seg->value])) {
                        $defaults[$seg->value] = $route->defaults[$seg->value];
                    }
                }

                $candidate = new MIRCandidate(
                    routeId: $route->id,
                    segments: $slice,
                    defaults: $defaults,
                    isStatic: $isStatic,
                    omittedOptional: $len < $total,
                    hasWildcard: $hasWildcard,
                    registrationOrder: $route->registrationOrder,
                );

                foreach ($route->methods as $method) {
                    $byMethod[$method][] = $candidate;
                }
            }
        }

        $methodTries = [];
        foreach ($byMethod as $method => &$candidates) {
            usort($candidates, static function (MIRCandidate $a, MIRCandidate $b): int {
                if ($a->isStatic !== $b->isStatic) {
                    return $a->isStatic ? -1 : 1;
                }

                $minLen = min(count($a->segments), count($b->segments));
                for ($i = 0; $i < $minLen; $i++) {
                    $ra = $a->segments[$i]->specificityRank();
                    $rb = $b->segments[$i]->specificityRank();
                    if ($ra !== $rb) {
                        return $rb <=> $ra;
                    }
                }

                if ($a->omittedOptional !== $b->omittedOptional) {
                    return $a->omittedOptional ? 1 : -1;
                }

                $lenDiff = count($a->segments) <=> count($b->segments);
                if ($lenDiff !== 0) {
                    return $lenDiff;
                }

                return $a->registrationOrder <=> $b->registrationOrder;
            });
            unset($candidates);

            $methodTries[$method] = $this->buildTrie($byMethod[$method]);
        }

        return new MIRModule(
            hir: $hir,
            methodCandidates: $byMethod,
            methodTries: $methodTries,
            routesById: $routesById,
        );
    }

    /**
     * @param list<MIRCandidate> $candidates
     */
    private function buildTrie(array $candidates): MIRTrieNode
    {
        $root = new MIRTrieNode($this->nextNodeId++);

        foreach ($candidates as $cand) {
            $node = $root;
            foreach ($cand->segments as $seg) {
                $matchedEdge = null;
                foreach ($node->edges as $edge) {
                    if (
                        $edge->kind === $seg->kind
                        && $edge->literalValue === ($seg->kind === 'literal' ? $seg->value : null)
                        && $edge->paramName === ($seg->kind !== 'literal' ? $seg->value : null)
                        && $edge->constraint->signature() === $seg->constraint->signature()
                    ) {
                        $matchedEdge = $edge;
                        break;
                    }
                }

                if ($matchedEdge === null) {
                    $target = new MIRTrieNode($this->nextNodeId++);
                    $matchedEdge = new MIRTrieEdge(
                        kind: $seg->kind,
                        literalValue: $seg->kind === 'literal' ? $seg->value : null,
                        paramName: $seg->kind !== 'literal' ? $seg->value : null,
                        constraint: $seg->constraint,
                        target: $target,
                        priority: $seg->specificityRank(),
                    );
                    $node->edges[] = $matchedEdge;
                }
                $node = $matchedEdge->target;
            }

            if ($node->terminalRouteId === null) {
                $node->terminalRouteId = $cand->routeId;
                $node->terminalDefaults = $cand->defaults;
                $node->terminalOmittedOptional = $cand->omittedOptional;
                $node->terminalRegistrationOrder = $cand->registrationOrder;
            }
        }

        return $root;
    }
}
