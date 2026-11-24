<?php

declare(strict_types=1);

namespace Routir\Transform\Optimization;

use Routir\Compiler\CompilationContext;
use Routir\Compiler\PassInterface;
use Routir\IR\LIR\LIRBasicBlock;
use Routir\IR\LIR\LIRFunction;
use Routir\IR\LIR\LIRModule;
use Routir\IR\LIR\ReturnMiss;
use Routir\IR\MIR\MIRCandidate;
use Routir\IR\MIR\MIRModule;
use Routir\Transform\Lowering\LIRLowering;
use Routir\Transform\Lowering\MIRLowering;

/**
 * O1 Pass: Extracts exact static routes into O(1) hash dispatch tables per HTTP method
 * and re-lowers only the remaining dynamic candidates.
 */
final class StaticDispatchPass implements PassInterface
{
    public function name(): string
    {
        return 'StaticDispatchPass';
    }

    public function run(LIRModule $ir, CompilationContext $context): LIRModule
    {
        $mir = new MIRLowering()->lower($ir->hir);
        $staticTables = [];
        $dynamicCandidatesByMethod = [];

        foreach ($mir->methodCandidates as $method => $candidates) {
            $dynamicCandidatesByMethod[$method] = [];
            foreach ($candidates as $cand) {
                if ($cand->isStatic && !$cand->omittedOptional && !$cand->hasWildcard) {
                    $path = $this->buildStaticPath($cand);
                    $staticTables[$method][$path] = [
                        'id' => $cand->routeId,
                        'defaults' => $cand->defaults,
                    ];
                } else {
                    $dynamicCandidatesByMethod[$method][] = $cand;
                }
            }
        }

        // Re-lower only dynamic candidates
        $dynamicMir = new MIRModule(
            hir: $mir->hir,
            methodCandidates: $dynamicCandidatesByMethod,
            methodTries: $mir->methodTries,
            routesById: $mir->routesById,
        );
        $dynamicLir = new LIRLowering()->lower($dynamicMir);

        // Ensure every method with static-only routes still has a valid LIRFunction
        $functions = $dynamicLir->functions;
        foreach ($staticTables as $method => $_) {
            if (!isset($functions[$method])) {
                $functions[$method] = new LIRFunction($method, 'miss', [
                    'miss' => new LIRBasicBlock('miss', [new ReturnMiss()]),
                ]);
            }
        }

        return new LIRModule(
            hir: $ir->hir,
            functions: $functions,
            staticTables: $staticTables,
        );
    }

    private function buildStaticPath(MIRCandidate $cand): string
    {
        if ($cand->segments === []) {
            return '/';
        }

        $parts = array_map(
            static fn ($seg): string => $seg->value,
            $cand->segments,
        );

        return '/' . implode('/', $parts);
    }
}
