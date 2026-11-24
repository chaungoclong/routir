<?php

declare(strict_types=1);

namespace Routir\Transform\Optimization;

use Routir\Compiler\CompilationContext;
use Routir\Compiler\PassInterface;
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
use Routir\Transform\Lowering\MIRLowering;

/**
 * O2 Pass: Factors contiguous runs of dynamic candidates sharing literal prefixes
 * into shared prefix guard blocks, preserving exact precedence and fallback semantics.
 */
final class TrieFactoringPass implements PassInterface
{
    private int $labelCounter = 0;

    public function name(): string
    {
        return 'TrieFactoringPass';
    }

    public function run(LIRModule $ir, CompilationContext $context): LIRModule
    {
        $mir = new MIRLowering()->lower($ir->hir);
        $functions = [];

        foreach ($mir->methodCandidates as $method => $candidates) {
            // If StaticDispatchPass already ran, filter out exact static routes
            $filtered = [];
            foreach ($candidates as $cand) {
                if (isset($ir->staticTables[$method]) && $cand->isStatic && !$cand->omittedOptional && !$cand->hasWildcard) {
                    continue;
                }
                $filtered[] = $cand;
            }

            $functions[$method] = $this->compileCandidateList($method, $filtered);
        }

        return new LIRModule(
            hir: $ir->hir,
            functions: $functions,
            staticTables: $ir->staticTables,
        );
    }

    /**
     * @param list<MIRCandidate> $candidates
     */
    private function compileCandidateList(string $method, array $candidates): LIRFunction
    {
        $this->labelCounter = 0;
        $blocks = [
            'miss' => new LIRBasicBlock('miss', [new ReturnMiss()]),
        ];

        if ($candidates === []) {
            return new LIRFunction($method, 'miss', $blocks);
        }

        $entry = $this->emitRun($candidates, 0, 'miss', $blocks);

        return new LIRFunction($method, $entry, $blocks);
    }

    /**
     * @param list<MIRCandidate> $candidates
     * @param array<string, LIRBasicBlock> $blocks
     */
    private function emitRun(array $candidates, int $depth, string $fallbackLabel, array &$blocks): string
    {
        if ($candidates === []) {
            return $fallbackLabel;
        }

        // Partition candidates into contiguous groups:
        // - If adjacent candidates at $depth are both 'literal' with the SAME value, group them!
        // - Otherwise emit single candidate.
        $groups = [];
        $i = 0;
        $n = count($candidates);
        while ($i < $n) {
            $cand = $candidates[$i];
            if (isset($cand->segments[$depth]) && $cand->segments[$depth]->kind === 'literal') {
                $lit = $cand->segments[$depth]->value;
                $group = [$cand];
                $j = $i + 1;
                while (
                    $j < $n
                    && isset($candidates[$j]->segments[$depth])
                    && $candidates[$j]->segments[$depth]->kind === 'literal'
                    && $candidates[$j]->segments[$depth]->value === $lit
                ) {
                    $group[] = $candidates[$j];
                    $j++;
                }
                $groups[] = ['type' => 'literal_group', 'literal' => $lit, 'cands' => $group];
                $i = $j;
            } else {
                $groups[] = ['type' => 'single', 'cand' => $cand];
                $i++;
            }
        }

        // Emit from last group backwards so each group knows its $nextFallback
        $nextLabel = $fallbackLabel;
        for ($g = count($groups) - 1; $g >= 0; $g--) {
            $grp = $groups[$g];
            if ($grp['type'] === 'literal_group' && count($grp['cands']) > 1) {
                $innerEntry = $this->emitRun($grp['cands'], $depth + 1, $nextLabel, $blocks);
                $readLabel = $this->newLabel('pfx_read');
                $guardLabel = $this->newLabel('pfx_lit');
                $var = '$seg' . $depth;
                $blocks[$readLabel] = new LIRBasicBlock($readLabel, [
                    new ReadSegment($depth, $var, $guardLabel, $nextLabel),
                ]);
                $blocks[$guardLabel] = new LIRBasicBlock($guardLabel, [
                    new GuardLiteral($var, $grp['literal'], $innerEntry, $nextLabel),
                ]);
                $nextLabel = $readLabel;
            } else {
                $cand = $grp['type'] === 'literal_group' ? $grp['cands'][0] : $grp['cand'];
                $nextLabel = $this->emitSingleCandidate($cand, $depth, $nextLabel, $blocks);
            }
        }

        return $nextLabel;
    }

    /**
     * @param array<string, LIRBasicBlock> $blocks
     */
    private function emitSingleCandidate(MIRCandidate $cand, int $startDepth, string $onFail, array &$blocks): string
    {
        $segCount = count($cand->segments);
        $entryLabel = $this->newLabel('c');

        if ($startDepth >= $segCount) {
            $matchLabel = $this->newLabel('m');
            $blocks[$entryLabel] = new LIRBasicBlock($entryLabel, [
                new ResetCaptures(),
                new BranchEOF($segCount, $matchLabel, $onFail),
            ]);
            $blocks[$matchLabel] = new LIRBasicBlock($matchLabel, [
                new ReturnMatch($cand->routeId, $cand->defaults),
            ]);

            return $entryLabel;
        }

        $curLabel = $entryLabel;
        $first = true;

        for ($sIdx = $startDepth; $sIdx < $segCount; $sIdx++) {
            $seg = $cand->segments[$sIdx];
            $instrs = [];
            if ($first) {
                $instrs[] = new ResetCaptures();
                $first = false;
            }

            $var = '$seg' . $sIdx;

            if ($seg->kind === 'wildcard') {
                $wcOk = $this->newLabel('wc');
                $instrs[] = new ReadSegment($sIdx, $var, $wcOk, $onFail);
                $blocks[$curLabel] = new LIRBasicBlock($curLabel, $instrs);
                $blocks[$wcOk] = new LIRBasicBlock($wcOk, [
                    new CaptureWildcard($seg->value, $sIdx),
                    new ReturnMatch($cand->routeId, $cand->defaults),
                ]);

                return $entryLabel;
            }

            $chkLabel = $this->newLabel('chk');
            $instrs[] = new ReadSegment($sIdx, $var, $chkLabel, $onFail);
            $blocks[$curLabel] = new LIRBasicBlock($curLabel, $instrs);

            $isLast = ($sIdx === $segCount - 1);
            $nextLabel = $this->newLabel('next');

            if ($seg->kind === 'literal') {
                $blocks[$chkLabel] = new LIRBasicBlock($chkLabel, [
                    new GuardLiteral($var, $seg->value, $nextLabel, $onFail),
                ]);
            } else {
                $capLabel = $this->newLabel('cap');
                $blocks[$chkLabel] = new LIRBasicBlock($chkLabel, [
                    new GuardConstraint($var, $seg->constraint, $capLabel, $onFail),
                ]);
                $blocks[$capLabel] = new LIRBasicBlock($capLabel, [
                    new Capture($seg->value, $var),
                    new Jump($nextLabel),
                ]);
            }

            if ($isLast) {
                $matchLabel = $this->newLabel('m');
                $blocks[$nextLabel] = new LIRBasicBlock($nextLabel, [
                    new BranchEOF($segCount, $matchLabel, $onFail),
                ]);
                $blocks[$matchLabel] = new LIRBasicBlock($matchLabel, [
                    new ReturnMatch($cand->routeId, $cand->defaults),
                ]);
            } else {
                $curLabel = $nextLabel;
            }
        }

        return $entryLabel;
    }

    private function newLabel(string $prefix): string
    {
        return $prefix . '_' . ($this->labelCounter++);
    }
}
