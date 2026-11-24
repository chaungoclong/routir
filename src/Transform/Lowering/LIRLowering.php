<?php

declare(strict_types=1);

namespace Routir\Transform\Lowering;

use Routir\IR\LIR\BranchEOF;
use Routir\IR\LIR\Capture;
use Routir\IR\LIR\CaptureWildcard;
use Routir\IR\LIR\GuardConstraint;
use Routir\IR\LIR\GuardLiteral;
use Routir\IR\LIR\Jump;
use Routir\IR\LIR\LIRBasicBlock;
use Routir\IR\LIR\LIRFunction;
use Routir\IR\LIR\LIRModule;
use Routir\IR\LIR\ReadSegment;
use Routir\IR\LIR\ResetCaptures;
use Routir\IR\LIR\ReturnMatch;
use Routir\IR\LIR\ReturnMiss;
use Routir\IR\MIR\MIRCandidate;
use Routir\IR\MIR\MIRModule;

final class LIRLowering
{
    public function lower(MIRModule $mir): LIRModule
    {
        $functions = [];
        foreach ($mir->methodCandidates as $method => $candidates) {
            $functions[$method] = $this->lowerMethod($method, $candidates);
        }

        return new LIRModule(hir: $mir->hir, functions: $functions);
    }

    /**
     * @param list<MIRCandidate> $candidates
     */
    private function lowerMethod(string $method, array $candidates): LIRFunction
    {
        $blocks = [];
        $missLabel = 'miss';
        $blocks[$missLabel] = new LIRBasicBlock($missLabel, [new ReturnMiss()]);

        if ($candidates === []) {
            return new LIRFunction($method, $missLabel, $blocks);
        }

        $total = count($candidates);
        for ($cIdx = 0; $cIdx < $total; $cIdx++) {
            $cand = $candidates[$cIdx];
            $nextCandLabel = ($cIdx + 1 < $total) ? 'cand_' . ($cIdx + 1) : $missLabel;
            $candEntry = 'cand_' . $cIdx;
            $segCount = count($cand->segments);

            if ($segCount === 0) {
                $blocks[$candEntry] = new LIRBasicBlock($candEntry, [
                    new ResetCaptures(),
                    new BranchEOF(0, $candEntry . '_match', $nextCandLabel),
                ]);
                $blocks[$candEntry . '_match'] = new LIRBasicBlock($candEntry . '_match', [
                    new ReturnMatch($cand->routeId, $cand->defaults),
                ]);
                continue;
            }

            $curLabel = $candEntry;
            $first = true;

            foreach ($cand->segments as $sIdx => $seg) {
                $instrs = [];
                if ($first) {
                    $instrs[] = new ResetCaptures();
                    $first = false;
                }

                $var = '$seg' . $sIdx;

                if ($seg->kind === 'wildcard') {
                    // Wildcard requires at least one nonempty segment at $sIdx
                    $checkLabel = $curLabel . '_wc_ok';
                    $instrs[] = new ReadSegment($sIdx, $var, $checkLabel, $nextCandLabel);
                    $blocks[$curLabel] = new LIRBasicBlock($curLabel, $instrs);

                    $blocks[$checkLabel] = new LIRBasicBlock($checkLabel, [
                        new CaptureWildcard($seg->value, $sIdx),
                        new ReturnMatch($cand->routeId, $cand->defaults),
                    ]);
                    continue 2;
                }

                $afterRead = $curLabel . '_s' . $sIdx . '_chk';
                $instrs[] = new ReadSegment($sIdx, $var, $afterRead, $nextCandLabel);
                $blocks[$curLabel] = new LIRBasicBlock($curLabel, $instrs);

                $isLast = ($sIdx === $segCount - 1);
                $onSegMatch = $isLast ? $candEntry . '_eof' : $candEntry . '_s' . ($sIdx + 1);

                if ($seg->kind === 'literal') {
                    $blocks[$afterRead] = new LIRBasicBlock($afterRead, [
                        new GuardLiteral($var, $seg->value, $onSegMatch, $nextCandLabel),
                    ]);
                } else {
                    $capLabel = $curLabel . '_s' . $sIdx . '_cap';
                    $blocks[$afterRead] = new LIRBasicBlock($afterRead, [
                        new GuardConstraint($var, $seg->constraint, $capLabel, $nextCandLabel),
                    ]);
                    $blocks[$capLabel] = new LIRBasicBlock($capLabel, [
                        new Capture($seg->value, $var),
                        new BranchEOF($isLast ? $segCount : -1, $isLast ? $candEntry . '_match' : $onSegMatch, $isLast ? $nextCandLabel : $onSegMatch),
                    ]);
                    if ($isLast) {
                        $blocks[$candEntry . '_match'] = new LIRBasicBlock($candEntry . '_match', [
                            new ReturnMatch($cand->routeId, $cand->defaults),
                        ]);
                    } else {
                        // Non-last param: directly jump to next segment
                        $blocks[$capLabel] = new LIRBasicBlock($capLabel, [
                            new Capture($seg->value, $var),
                            new Jump($onSegMatch),
                        ]);
                    }
                    $curLabel = $onSegMatch;
                    continue;
                }

                if ($isLast) {
                    $blocks[$candEntry . '_eof'] = new LIRBasicBlock($candEntry . '_eof', [
                        new BranchEOF($segCount, $candEntry . '_match', $nextCandLabel),
                    ]);
                    $blocks[$candEntry . '_match'] = new LIRBasicBlock($candEntry . '_match', [
                        new ReturnMatch($cand->routeId, $cand->defaults),
                    ]);
                } else {
                    $curLabel = $onSegMatch;
                }
            }
        }

        return new LIRFunction($method, 'cand_0', $blocks);
    }
}
