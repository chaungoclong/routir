<?php

declare(strict_types=1);

namespace Routir\Interpreter;

use Routir\Contracts\MatcherInterface;
use Routir\Contracts\MatchResult;
use Routir\Contracts\MatchStatus;
use Routir\Exception\InvalidRequestTargetException;
use Routir\Runtime\ConstraintRegistry;
use Routir\Runtime\PathNormalizer;
use Routir\Semantic\NormalizedRoute;
use Routir\Semantic\NormalizedSegment;
use Routir\Semantic\SemanticResult;

final class ReferenceMatcher implements MatcherInterface
{
    /** @var list<string> */
    private const array METHOD_ORDER = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * @var list<array{
     *   route: NormalizedRoute,
     *   segments: list<NormalizedSegment>,
     *   isStatic: bool,
     *   omittedOptional: bool,
     *   hasWildcard: bool
     * }>
     */
    private array $candidates = [];

    public function __construct(SemanticResult $semantic)
    {
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
                $this->candidates[] = [
                    'route' => $route,
                    'segments' => $slice,
                    'isStatic' => $isStatic,
                    'omittedOptional' => $len < $total,
                    'hasWildcard' => $hasWildcard,
                ];
            }
        }

        usort($this->candidates, static function (array $a, array $b): int {
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
    }

    public function match(string $method, string $requestTarget): MatchResult
    {
        try {
            $pathSegments = PathNormalizer::normalize($requestTarget);
        } catch (InvalidRequestTargetException $e) {
            return new MatchResult(
                status: MatchStatus::InvalidRequestTarget,
                errorReason: $e->getMessage(),
            );
        }

        // 1. Direct method match
        $direct = $this->matchForMethod($method, $pathSegments);
        if ($direct !== null) {
            return new MatchResult(
                status: MatchStatus::Found,
                routeId: $direct['route']->id,
                params: $direct['params'],
            );
        }

        // 2. Implicit HEAD -> GET fallback
        if ($method === 'HEAD') {
            $getMatch = $this->matchForMethod('GET', $pathSegments);
            if ($getMatch !== null) {
                return new MatchResult(
                    status: MatchStatus::Found,
                    routeId: $getMatch['route']->id,
                    params: $getMatch['params'],
                    headFallback: true,
                );
            }
        }

        // 3. Collect allowed methods for this path
        $allowed = $this->collectAllowedMethods($pathSegments);
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
     * @param list<string> $pathSegments
     * @return array{route: NormalizedRoute, params: array<string, string>}|null
     */
    private function matchForMethod(string $method, array $pathSegments): ?array
    {
        foreach ($this->candidates as $candidate) {
            if (!in_array($method, $candidate['route']->methods, true)) {
                continue;
            }

            $params = $this->matchCandidate($candidate['segments'], $candidate['hasWildcard'], $candidate['route'], $pathSegments);
            if ($params !== null) {
                return [
                    'route' => $candidate['route'],
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    /**
     * @param list<string> $pathSegments
     * @return list<string>
     */
    private function collectAllowedMethods(array $pathSegments): array
    {
        $methods = [];
        foreach ($this->candidates as $candidate) {
            $params = $this->matchCandidate($candidate['segments'], $candidate['hasWildcard'], $candidate['route'], $pathSegments);
            if ($params !== null) {
                foreach ($candidate['route']->methods as $m) {
                    $methods[$m] = true;
                }
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
     * @param list<NormalizedSegment> $candidateSegments
     * @param list<string> $pathSegments
     * @return array<string, string>|null
     */
    private function matchCandidate(
        array $candidateSegments,
        bool $hasWildcard,
        NormalizedRoute $route,
        array $pathSegments,
    ): ?array {
        $candCount = count($candidateSegments);
        $pathCount = count($pathSegments);

        if ($hasWildcard) {
            // Wildcard must match at least 1 nonempty segment
            if ($pathCount < $candCount) {
                return null;
            }
        } elseif ($pathCount !== $candCount) {
            return null;
        }

        $params = [];
        for ($i = 0; $i < $candCount; $i++) {
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

            // Parameter segment (nonempty valid segment)
            $val = $pathSegments[$i];
            if ($val === '') {
                return null;
            }

            $constraint = $seg->constraint;
            $matched = match ($constraint->kind) {
                'none' => true,
                'builtin' => ConstraintRegistry::matchesBuiltin($constraint->builtinName ?? '', $val),
                'in' => ConstraintRegistry::matchesIn($constraint->allowedValues, $val),
                'regex' => ConstraintRegistry::matchesRegex($constraint->compiledRegex ?? '', $val),
                default => false,
            };

            if (!$matched) {
                return null;
            }

            $params[$seg->value] = $val;
        }

        // Apply defaults ONLY to absent optional parameters
        foreach ($route->segments as $seg) {
            if ($seg->optional && !array_key_exists($seg->value, $params)) {
                if (array_key_exists($seg->value, $route->defaults)) {
                    $params[$seg->value] = $route->defaults[$seg->value];
                }
            }
        }

        return $params;
    }
}
