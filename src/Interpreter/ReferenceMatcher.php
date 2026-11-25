<?php

declare(strict_types=1);

namespace Routir\Interpreter;

use Routir\Contracts\MatcherInterface;
use Routir\Contracts\MatchResult;
use Routir\Contracts\MatchStatus;
use Routir\Exception\InvalidRequestTargetException;
use Routir\Runtime\ConstraintRegistry;
use Routir\Runtime\PathNormalizer;
use Routir\Semantic\NormalizedConstraint;
use Routir\Semantic\NormalizedRoute;
use Routir\Semantic\NormalizedSegment;
use Routir\Semantic\SemanticResult;

/**
 * Reference interpreted route matcher with static hash lookup and per-method runtime trie.
 */
final class ReferenceMatcher implements MatcherInterface
{
    /** @var list<string> */
    private const array METHOD_ORDER = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** @var list<string> */
    private array $registeredMethods = [];

    /** @var array<string, array<string, array{id: int, defaults: array<string, string>}>> */
    private array $staticTables = [];

    /**
     * @var array<string, list<array{
     *   type: string,
     *   map?: array<string, mixed>,
     *   param?: string,
     *   constraint?: NormalizedConstraint,
     *   child?: mixed,
     *   cand?: array{routeId: int, segments: list<NormalizedSegment>, defaults: array<string, string>, hasWildcard: bool}
     * }>>
     */
    private array $tries = [];

    public function __construct(SemanticResult $semantic)
    {
        /**
         * @var list<array{
         *   route: NormalizedRoute,
         *   segments: list<NormalizedSegment>,
         *   defaults: array<string, string>,
         *   isStatic: bool,
         *   omittedOptional: bool,
         *   hasWildcard: bool
         * }> $candidates
         */
        $candidates = [];

        foreach ($semantic->routes as $route) {
            $total = count($route->segments);
            $required = $route->requiredSegmentCount();

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

                $defaults = [];
                foreach ($route->segments as $idx => $seg) {
                    if ($idx >= $len && $seg->optional && array_key_exists($seg->value, $route->defaults)) {
                        $defaults[$seg->value] = $route->defaults[$seg->value];
                    }
                }

                $candidates[] = [
                    'route' => $route,
                    'segments' => $slice,
                    'defaults' => $defaults,
                    'isStatic' => $isStatic,
                    'omittedOptional' => $len < $total,
                    'hasWildcard' => $hasWildcard,
                ];
            }
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($a['isStatic'] !== $b['isStatic']) {
                return $a['isStatic'] ? -1 : 1;
            }

            $minLen = min(count($a['segments']), count($b['segments']));
            for ($i = 0; $i < $minLen; $i++) {
                $ra = $a['segments'][$i]->specificityRank();
                $rb = $b['segments'][$i]->specificityRank();
                if ($ra !== $rb) {
                    return $rb <=> $ra; // higher rank first
                }
            }

            if ($a['omittedOptional'] !== $b['omittedOptional']) {
                return $a['omittedOptional'] ? 1 : -1; // exact terminal beats omitted optional
            }

            $lenDiff = count($a['segments']) <=> count($b['segments']);
            if ($lenDiff !== 0) {
                return $lenDiff;
            }

            return $a['route']->registrationOrder <=> $b['route']->registrationOrder;
        });

        /** @var array<string, list<array{routeId: int, segments: list<NormalizedSegment>, defaults: array<string, string>, hasWildcard: bool}>> $dynamicByMethod */
        $dynamicByMethod = [];

        foreach ($candidates as $cand) {
            foreach ($cand['route']->methods as $method) {
                if (!isset($dynamicByMethod[$method])) {
                    $dynamicByMethod[$method] = [];
                }

                if ($cand['isStatic'] && !$cand['omittedOptional'] && !$cand['hasWildcard']) {
                    $parts = [];
                    foreach ($cand['segments'] as $seg) {
                        $parts[] = $seg->value;
                    }
                    $path = $parts === [] ? '/' : '/' . implode('/', $parts);
                    if (!isset($this->staticTables[$method][$path])) {
                        $this->staticTables[$method][$path] = [
                            'id' => $cand['route']->id,
                            'defaults' => $cand['defaults'],
                        ];
                    }
                    continue;
                }

                $dynamicByMethod[$method][] = [
                    'routeId' => $cand['route']->id,
                    'segments' => $cand['segments'],
                    'defaults' => $cand['defaults'],
                    'hasWildcard' => $cand['hasWildcard'],
                ];
            }
        }

        $this->registeredMethods = array_keys($dynamicByMethod);
        foreach ($dynamicByMethod as $method => $methodCands) {
            $this->tries[$method] = $this->buildTrieNode($methodCands, 0);
        }
    }

    public function match(string $method, string $requestTarget): MatchResult
    {
        if (isset($this->staticTables[$method][$requestTarget]) && !str_contains($requestTarget, '%')) {
            $hit = $this->staticTables[$method][$requestTarget];

            return new MatchResult(
                status: MatchStatus::Found,
                routeId: $hit['id'],
                params: $hit['defaults'],
            );
        }

        try {
            $pathSegments = PathNormalizer::normalize($requestTarget);
        } catch (InvalidRequestTargetException $e) {
            return new MatchResult(
                status: MatchStatus::InvalidRequestTarget,
                errorReason: $e->getMessage(),
            );
        }

        $segCount = count($pathSegments);
        $rawPath = $segCount === 0
            ? '/'
            : ((!str_contains($requestTarget, '%') && !str_contains($requestTarget, '?') && !str_contains($requestTarget, '#'))
                ? $requestTarget
                : '/' . implode('/', $pathSegments));

        // 1. Direct method match
        $direct = $this->matchForMethod($method, $pathSegments, $segCount, $rawPath);
        if ($direct !== null) {
            return new MatchResult(
                status: MatchStatus::Found,
                routeId: $direct['routeId'],
                params: $direct['params'],
            );
        }

        // 2. Implicit HEAD -> GET fallback
        if ($method === 'HEAD') {
            $getMatch = $this->matchForMethod('GET', $pathSegments, $segCount, $rawPath);
            if ($getMatch !== null) {
                return new MatchResult(
                    status: MatchStatus::Found,
                    routeId: $getMatch['routeId'],
                    params: $getMatch['params'],
                    headFallback: true,
                );
            }
        }

        // 3. Collect allowed methods for this path
        $allowed = $this->collectAllowedMethods($pathSegments, $segCount, $rawPath);
        if ($allowed !== []) {
            if ($method === 'OPTIONS') {
                return new MatchResult(
                    status: MatchStatus::OptionsSynthesized,
                    allowedMethods: $allowed,
                );
            }

            return new MatchResult(
                status: MatchStatus::MethodNotAllowed,
                allowedMethods: $allowed,
            );
        }

        return new MatchResult(status: MatchStatus::NotFound);
    }

    /**
     * @param list<array{routeId: int, segments: list<NormalizedSegment>, defaults: array<string, string>, hasWildcard: bool}> $candidates
     *
     * @return list<array{
     *   type: string,
     *   map?: array<string, mixed>,
     *   param?: string,
     *   constraint?: NormalizedConstraint,
     *   child?: mixed,
     *   cand?: array{routeId: int, segments: list<NormalizedSegment>, defaults: array<string, string>, hasWildcard: bool}
     * }>
     */
    private function buildTrieNode(array $candidates, int $depth): array
    {
        if ($candidates === []) {
            return [];
        }

        $stages = [];
        $i = 0;
        $n = count($candidates);

        while ($i < $n) {
            $cand = $candidates[$i];
            $seg = $cand['segments'][$depth] ?? null;

            if ($seg !== null && $seg->kind === 'literal') {
                /** @var array<string, list<array{routeId: int, segments: list<NormalizedSegment>, defaults: array<string, string>, hasWildcard: bool}>> $buckets */
                $buckets = [];
                $j = $i;
                while (
                    $j < $n
                    && isset($candidates[$j]['segments'][$depth])
                    && $candidates[$j]['segments'][$depth]->kind === 'literal'
                ) {
                    $lit = $candidates[$j]['segments'][$depth]->value;
                    $buckets[$lit][] = $candidates[$j];
                    $j++;
                }

                $map = [];
                foreach ($buckets as $lit => $bucketCands) {
                    $map[(string) $lit] = $this->buildTrieNode($bucketCands, $depth + 1);
                }
                $stages[] = ['type' => 'literal_map', 'map' => $map];
                $i = $j;
            } elseif ($seg !== null && $seg->kind === 'param') {
                $paramName = $seg->value;
                $sig = $seg->constraint->signature();
                $group = [$cand];
                $j = $i + 1;
                while (
                    $j < $n
                    && isset($candidates[$j]['segments'][$depth])
                    && $candidates[$j]['segments'][$depth]->kind === 'param'
                    && $candidates[$j]['segments'][$depth]->value === $paramName
                    && $candidates[$j]['segments'][$depth]->constraint->signature() === $sig
                ) {
                    $group[] = $candidates[$j];
                    $j++;
                }

                if (count($group) > 1) {
                    $stages[] = [
                        'type' => 'param_group',
                        'param' => $paramName,
                        'constraint' => $seg->constraint,
                        'child' => $this->buildTrieNode($group, $depth + 1),
                    ];
                } else {
                    $stages[] = ['type' => 'single', 'cand' => $cand];
                }
                $i = $j;
            } else {
                $stages[] = ['type' => 'single', 'cand' => $cand];
                $i++;
            }
        }

        return $stages;
    }

    /**
     * @param list<string> $pathSegments
     *
     * @return array{routeId: int, params: array<string, string>}|null
     */
    private function matchForMethod(string $method, array $pathSegments, int $segCount, string $rawPath): ?array
    {
        if (isset($this->staticTables[$method][$rawPath])) {
            $hit = $this->staticTables[$method][$rawPath];

            return [
                'routeId' => $hit['id'],
                'params' => $hit['defaults'],
            ];
        }

        $trie = $this->tries[$method] ?? null;
        if ($trie === null || $trie === []) {
            return null;
        }

        return $this->matchTrie($trie, $pathSegments, $segCount, 0, []);
    }

    /**
     * @param list<array{
     *   type: string,
     *   map?: array<string, mixed>,
     *   param?: string,
     *   constraint?: NormalizedConstraint,
     *   child?: mixed,
     *   cand?: array{routeId: int, segments: list<NormalizedSegment>, defaults: array<string, string>, hasWildcard: bool}
     * }> $stages
     * @param list<string> $pathSegments
     * @param array<string, string> $captured
     *
     * @return array{routeId: int, params: array<string, string>}|null
     */
    private function matchTrie(array $stages, array $pathSegments, int $segCount, int $depth, array $captured): ?array
    {
        foreach ($stages as $stage) {
            if ($stage['type'] === 'literal_map') {
                if ($depth >= $segCount) {
                    continue;
                }
                $segVal = $pathSegments[$depth];
                /** @var array<string, list<array{type: string, map?: array<string, mixed>, param?: string, constraint?: NormalizedConstraint, child?: mixed, cand?: array{routeId: int, segments: list<NormalizedSegment>, defaults: array<string, string>, hasWildcard: bool}}>> $map */
                $map = $stage['map'] ?? [];
                if (isset($map[$segVal])) {
                    $hit = $this->matchTrie($map[$segVal], $pathSegments, $segCount, $depth + 1, $captured);
                    if ($hit !== null) {
                        return $hit;
                    }
                }
                continue;
            }

            if ($stage['type'] === 'param_group') {
                if ($depth >= $segCount) {
                    continue;
                }
                $val = $pathSegments[$depth];
                if ($val === '' || !isset($stage['constraint'], $stage['param'], $stage['child'])) {
                    continue;
                }
                if (!$this->matchesConstraint($stage['constraint'], $val)) {
                    continue;
                }
                $nextCaptured = $captured;
                $nextCaptured[$stage['param']] = $val;
                /** @var list<array{type: string, map?: array<string, mixed>, param?: string, constraint?: NormalizedConstraint, child?: mixed, cand?: array{routeId: int, segments: list<NormalizedSegment>, defaults: array<string, string>, hasWildcard: bool}}> $child */
                $child = $stage['child'];
                $hit = $this->matchTrie($child, $pathSegments, $segCount, $depth + 1, $nextCaptured);
                if ($hit !== null) {
                    return $hit;
                }
                continue;
            }

            if (isset($stage['cand'])) {
                $params = $this->matchCandidateFromDepth($stage['cand'], $pathSegments, $segCount, $depth, $captured);
                if ($params !== null) {
                    return [
                        'routeId' => $stage['cand']['routeId'],
                        'params' => $params,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $pathSegments
     *
     * @return list<string>
     */
    private function collectAllowedMethods(array $pathSegments, int $segCount, string $rawPath): array
    {
        $methods = [];
        foreach ($this->registeredMethods as $m) {
            if ($this->matchForMethod($m, $pathSegments, $segCount, $rawPath) !== null) {
                $methods[$m] = true;
            }
        }

        if ($methods === []) {
            return [];
        }

        if (isset($methods['GET'])) {
            $methods['HEAD'] = true;
        }
        $methods['OPTIONS'] = true;

        $ordered = [];
        foreach (self::METHOD_ORDER as $std) {
            if (isset($methods[$std])) {
                $ordered[] = $std;
                unset($methods[$std]);
            }
        }

        if ($methods !== []) {
            $extra = array_keys($methods);
            sort($extra);
            foreach ($extra as $m) {
                $ordered[] = $m;
            }
        }

        return $ordered;
    }

    /**
     * @param array{routeId: int, segments: list<NormalizedSegment>, defaults: array<string, string>, hasWildcard: bool} $candidate
     * @param list<string> $pathSegments
     * @param array<string, string> $params
     *
     * @return array<string, string>|null
     */
    private function matchCandidateFromDepth(
        array $candidate,
        array $pathSegments,
        int $pathCount,
        int $startDepth,
        array $params,
    ): ?array {
        $candidateSegments = $candidate['segments'];
        $candCount = count($candidateSegments);

        if ($candidate['hasWildcard']) {
            if ($pathCount < $candCount) {
                return null;
            }
        } elseif ($pathCount !== $candCount) {
            return null;
        }

        for ($i = $startDepth; $i < $candCount; $i++) {
            $seg = $candidateSegments[$i];

            if ($seg->kind === 'literal') {
                if ($pathSegments[$i] !== $seg->value) {
                    return null;
                }
                continue;
            }

            if ($seg->kind === 'wildcard') {
                $rest = array_slice($pathSegments, $i);
                foreach ($rest as $part) {
                    if ($part === '') {
                        return null;
                    }
                }
                $params[$seg->value] = implode('/', $rest);
                break;
            }

            $val = $pathSegments[$i];
            if ($val === '' || !$this->matchesConstraint($seg->constraint, $val)) {
                return null;
            }

            $params[$seg->value] = $val;
        }

        if ($candidate['defaults'] !== []) {
            $params += $candidate['defaults'];
        }

        return $params;
    }

    private function matchesConstraint(NormalizedConstraint $constraint, string $val): bool
    {
        return match ($constraint->kind) {
            'none' => true,
            'builtin' => ConstraintRegistry::matchesBuiltin($constraint->builtinName ?? '', $val),
            'in' => ConstraintRegistry::matchesIn($constraint->allowedValues, $val),
            'regex' => ConstraintRegistry::matchesRegex($constraint->compiledRegex ?? '', $val),
            default => false,
        };
    }
}
