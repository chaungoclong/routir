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
use Routir\IR\LIR\SwitchLiteral;
use Routir\IR\MIR\MIRCandidate;
use Routir\Transform\Lowering\MIRLowering;

/**
 * O2 Pass: Factors dynamic candidates sharing literal or identical parameter prefixes
 * into shared trie guard blocks and O(1) literal switch dispatch, preserving exact
 * precedence and fallback semantics.
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

        $entry = $this->emitRun($candidates, 0, 'miss', [], $blocks);

        return new LIRFunction($method, $entry, $blocks);
    }

    /**
     * @param list<MIRCandidate> $candidates
     * @param array<string, string> $pendingCaptures Map of paramName => segmentVar from outer shared param nodes.
     * @param array<string, LIRBasicBlock> $blocks
     */
    private function emitRun(
        array $candidates,
        int $depth,
        string $fallbackLabel,
        array $pendingCaptures,
        array &$blocks,
    ): string {
        if ($candidates === []) {
            return $fallbackLabel;
        }

        // Partition candidates into contiguous runs:
        // 1. Contiguous run of 'literal' at $depth -> bucket by literal value (distinct literals are mutually exclusive).
        // 2. Contiguous run of identical 'param' at $depth (same paramName & constraint signature).
        // 3. Single candidate (EOF or wildcard).
        $groups = [];
        $i = 0;
        $n = count($candidates);
        while ($i < $n) {
            $cand = $candidates[$i];
            $seg = $cand->segments[$depth] ?? null;

            if ($seg !== null && $seg->kind === 'literal') {
                /** @var array<string, list<MIRCandidate>> $buckets */
                $buckets = [];
                $j = $i;
                while (
                    $j < $n
                    && isset($candidates[$j]->segments[$depth])
                    && $candidates[$j]->segments[$depth]->kind === 'literal'
                ) {
                    $lit = $candidates[$j]->segments[$depth]->value;
                    $buckets[$lit][] = $candidates[$j];
                    $j++;
                }
                $groups[] = ['type' => 'literal_run', 'buckets' => $buckets];
                $i = $j;
            } elseif ($seg !== null && $seg->kind === 'param') {
                $paramName = $seg->value;
                $sig = $seg->constraint->signature();
                $group = [$cand];
                $j = $i + 1;
                while (
                    $j < $n
                    && isset($candidates[$j]->segments[$depth])
                    && $candidates[$j]->segments[$depth]->kind === 'param'
                    && $candidates[$j]->segments[$depth]->value === $paramName
                    && $candidates[$j]->segments[$depth]->constraint->signature() === $sig
                ) {
                    $group[] = $candidates[$j];
                    $j++;
                }
                $groups[] = ['type' => 'param_group', 'seg' => $seg, 'cands' => $group];
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
            if ($grp['type'] === 'literal_run') {
                $nextLabel = $this->emitLiteralRun($grp['buckets'], $depth, $nextLabel, $pendingCaptures, $blocks);
            } elseif ($grp['type'] === 'param_group' && count($grp['cands']) > 1) {
                $var = '$seg' . $depth;
                $nextCaptures = $pendingCaptures;
                $nextCaptures[$grp['seg']->value] = $var;
                $innerEntry = $this->emitRun($grp['cands'], $depth + 1, $nextLabel, $nextCaptures, $blocks);

                $readLabel = $this->newLabel('pfx_read');
                $guardLabel = $this->newLabel('pfx_prm');
                $blocks[$readLabel] = new LIRBasicBlock($readLabel, [
                    new ReadSegment($depth, $var, $guardLabel, $nextLabel),
                ]);
                $blocks[$guardLabel] = new LIRBasicBlock($guardLabel, [
                    new GuardConstraint($var, $grp['seg']->constraint, $innerEntry, $nextLabel),
                ]);
                $nextLabel = $readLabel;
            } else {
                $cand = $grp['type'] === 'param_group' ? $grp['cands'][0] : $grp['cand'];
                $nextLabel = $this->emitSingleCandidate($cand, $depth, $nextLabel, $pendingCaptures, $blocks);
            }
        }

        return $nextLabel;
    }

    /**
     * @param array<string, list<MIRCandidate>> $buckets
     * @param array<string, string> $pendingCaptures
     * @param array<string, LIRBasicBlock> $blocks
     */
    private function emitLiteralRun(
        array $buckets,
        int $depth,
        string $onFail,
        array $pendingCaptures,
        array &$blocks,
    ): string {
        if (count($buckets) === 1) {
            $lit = (string) array_key_first($buckets);
            $cands = $buckets[$lit];
            if (count($cands) === 1) {
                return $this->emitSingleCandidate($cands[0], $depth, $onFail, $pendingCaptures, $blocks);
            }

            $innerEntry = $this->emitRun($cands, $depth + 1, $onFail, $pendingCaptures, $blocks);
            $readLabel = $this->newLabel('pfx_read');
            $guardLabel = $this->newLabel('pfx_lit');
            $var = '$seg' . $depth;
            $blocks[$readLabel] = new LIRBasicBlock($readLabel, [
                new ReadSegment($depth, $var, $guardLabel, $onFail),
            ]);
            $blocks[$guardLabel] = new LIRBasicBlock($guardLabel, [
                new GuardLiteral($var, $lit, $innerEntry, $onFail),
            ]);

            return $readLabel;
        }

        // Partition into strict-switchable non-numeric literals and numeric literals
        // (PHP switch uses == which coerces numeric strings like '01' == '1').
        /** @var array<string, string> $switchCases */
        $switchCases = [];
        /** @var list<array{lit: string, target: string}> $numericGuards */
        $numericGuards = [];

        foreach ($buckets as $lit => $cands) {
            $litStr = (string) $lit;
            $target = count($cands) === 1
                ? $this->emitSingleCandidate($cands[0], $depth + 1, $onFail, $pendingCaptures, $blocks)
                : $this->emitRun($cands, $depth + 1, $onFail, $pendingCaptures, $blocks);

            if (is_numeric($litStr)) {
                $numericGuards[] = ['lit' => $litStr, 'target' => $target];
            } else {
                $switchCases[$litStr] = $target;
            }
        }

        $var = '$seg' . $depth;
        $dispatchLabel = $onFail;

        if (count($switchCases) >= 2) {
            $swLabel = $this->newLabel('sw');
            $blocks[$swLabel] = new LIRBasicBlock($swLabel, [
                new SwitchLiteral($var, $switchCases, $dispatchLabel),
            ]);
            $dispatchLabel = $swLabel;
        } elseif (count($switchCases) === 1) {
            $litStr = (string) array_key_first($switchCases);
            $glLabel = $this->newLabel('gl');
            $blocks[$glLabel] = new LIRBasicBlock($glLabel, [
                new GuardLiteral($var, $litStr, $switchCases[$litStr], $dispatchLabel),
            ]);
            $dispatchLabel = $glLabel;
        }

        for ($k = count($numericGuards) - 1; $k >= 0; $k--) {
            $ng = $numericGuards[$k];
            $ngLabel = $this->newLabel('ng');
            $blocks[$ngLabel] = new LIRBasicBlock($ngLabel, [
                new GuardLiteral($var, $ng['lit'], $ng['target'], $dispatchLabel),
            ]);
            $dispatchLabel = $ngLabel;
        }

        $readLabel = $this->newLabel('pfx_read');
        $blocks[$readLabel] = new LIRBasicBlock($readLabel, [
            new ReadSegment($depth, $var, $dispatchLabel, $onFail),
        ]);

        return $readLabel;
    }

    /**
     * @param array<string, string> $pendingCaptures
     * @param array<string, LIRBasicBlock> $blocks
     */
    private function emitSingleCandidate(
        MIRCandidate $cand,
        int $startDepth,
        string $onFail,
        array $pendingCaptures,
        array &$blocks,
    ): string {
        $segCount = count($cand->segments);
        $entryLabel = $this->newLabel('c');

        if ($startDepth >= $segCount) {
            $matchLabel = $this->newLabel('m');
            $blocks[$entryLabel] = new LIRBasicBlock($entryLabel, [
                new BranchEOF($segCount, $matchLabel, $onFail),
            ]);
            $blocks[$matchLabel] = new LIRBasicBlock($matchLabel, [
                ...$this->buildCaptureInstructions($pendingCaptures),
                new ReturnMatch($cand->routeId, $cand->defaults),
            ]);

            return $entryLabel;
        }

        $curLabel = $entryLabel;
        $captures = $pendingCaptures;

        for ($sIdx = $startDepth; $sIdx < $segCount; $sIdx++) {
            $seg = $cand->segments[$sIdx];
            $var = '$seg' . $sIdx;

            if ($seg->kind === 'wildcard') {
                $wcOk = $this->newLabel('wc');
                $blocks[$curLabel] = new LIRBasicBlock($curLabel, [
                    new ReadSegment($sIdx, $var, $wcOk, $onFail),
                ]);
                $blocks[$wcOk] = new LIRBasicBlock($wcOk, [
                    ...$this->buildCaptureInstructions($captures),
                    new CaptureWildcard($seg->value, $sIdx),
                    new ReturnMatch($cand->routeId, $cand->defaults),
                ]);

                return $entryLabel;
            }

            $chkLabel = $this->newLabel('chk');
            $blocks[$curLabel] = new LIRBasicBlock($curLabel, [
                new ReadSegment($sIdx, $var, $chkLabel, $onFail),
            ]);

            $isLast = ($sIdx === $segCount - 1);
            $nextLabel = $this->newLabel('next');

            if ($seg->kind === 'literal') {
                $blocks[$chkLabel] = new LIRBasicBlock($chkLabel, [
                    new GuardLiteral($var, $seg->value, $nextLabel, $onFail),
                ]);
            } else {
                $captures[$seg->value] = $var;
                $blocks[$chkLabel] = new LIRBasicBlock($chkLabel, [
                    new GuardConstraint($var, $seg->constraint, $nextLabel, $onFail),
                ]);
            }

            if ($isLast) {
                $matchLabel = $this->newLabel('m');
                $blocks[$nextLabel] = new LIRBasicBlock($nextLabel, [
                    new BranchEOF($segCount, $matchLabel, $onFail),
                ]);
                $blocks[$matchLabel] = new LIRBasicBlock($matchLabel, [
                    ...$this->buildCaptureInstructions($captures),
                    new ReturnMatch($cand->routeId, $cand->defaults),
                ]);
            } else {
                $curLabel = $nextLabel;
            }
        }

        return $entryLabel;
    }

    /**
     * @param array<string, string> $captures
     *
     * @return list<ResetCaptures|Capture>
     */
    private function buildCaptureInstructions(array $captures): array
    {
        $instrs = [new ResetCaptures()];
        foreach ($captures as $paramName => $var) {
            $instrs[] = new Capture($paramName, $var);
        }

        return $instrs;
    }

    private function newLabel(string $prefix): string
    {
        return $prefix . '_' . ($this->labelCounter++);
    }
}
