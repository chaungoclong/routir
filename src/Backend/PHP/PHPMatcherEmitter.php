<?php

declare(strict_types=1);

namespace Routir\Backend\PHP;

use LogicException;
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
use Routir\Semantic\NormalizedConstraint;

final class PHPMatcherEmitter
{
    private const int MAX_SPECIALIZED_BLOCKS = 512;

    private const int MAX_SPECIALIZED_SEGMENT_COUNTS = 3;

    /** @var array<string, int> Bitmask values for standard HTTP methods in canonical order. */
    private const array METHOD_BITS = [
        'GET' => 1,
        'HEAD' => 2,
        'POST' => 4,
        'PUT' => 8,
        'PATCH' => 16,
        'DELETE' => 32,
        'OPTIONS' => 64,
    ];

    /**
     * Emit matcher.php from an LIRModule.
     *
     * @param LIRModule $module LIR module.
     *
     * @return string Generated PHP code.
     *
     * @throws LogicException When an unknown LIR instruction is encountered.
     */
    public function emit(LIRModule $module): string
    {
        $methods = array_keys($module->functions);

        $activeMethods = [];
        foreach ($module->functions as $method => $fn) {
            if (!$this->isEmptyFunction($fn)) {
                $activeMethods[] = $method;
            }
        }

        $customMethods = array_values(array_filter(
            $methods,
            static fn (string $m): bool => !isset(self::METHOD_BITS[$m]),
        ));

        $code = "<?php\n\ndeclare(strict_types=1);\n\n";
        $code .= "use Routir\\Contracts\\MatcherInterface;\n";
        $code .= "use Routir\\Contracts\\MatchResult;\n";
        $code .= "use Routir\\Contracts\\MatchStatus;\n";
        $code .= "use Routir\\Exception\\InvalidRequestTargetException;\n";
        $code .= "use Routir\\Runtime\\ConstraintRegistry;\n";
        $code .= "use Routir\\Runtime\\PathNormalizer;\n\n";
        $code .= "return new class implements MatcherInterface {\n";

        if ($customMethods !== []) {
            $code .= '    private const array CUSTOM_METHODS = ' . CompactPhpExporter::export($customMethods) . ";\n\n";
        }

        $hasStatic = $module->staticTables !== [];
        if ($hasStatic) {
            /** @var array<string, array<string, int|array{0: int, 1: array<string, string>}>> $compactStatic */
            $compactStatic = [];
            /** @var array<string, int> $staticMethodMasks */
            $staticMethodMasks = [];

            foreach ($module->staticTables as $method => $entries) {
                $bit = self::METHOD_BITS[$method] ?? 0;
                foreach ($entries as $path => $entry) {
                    $compactStatic[$method][$path] = $entry['defaults'] === []
                        ? $entry['id']
                        : [$entry['id'], $entry['defaults']];
                    if ($bit !== 0) {
                        $staticMethodMasks[$path] = ($staticMethodMasks[$path] ?? 0) | $bit;
                    }
                }
            }

            $code .= "    /** @var array<string, array<string, int|array{0: int, 1: array<string, string>}>> */\n";
            $code .= '    private const array STATIC_TABLES = ' . CompactPhpExporter::exportTopLevelMap($compactStatic) . ";\n\n";

            if ($staticMethodMasks !== []) {
                $code .= "    /** @var array<string, int> */\n";
                $code .= '    private const array STATIC_METHOD_MASKS = ' . CompactPhpExporter::export($staticMethodMasks) . ";\n\n";
            }
        }

        // Emit match() entrypoint
        $code .= "    public function match(string \$method, string \$requestTarget): MatchResult\n    {\n";
        if ($hasStatic) {
            $code .= "        if (isset(self::STATIC_TABLES[\$method][\$requestTarget])) {\n";
            $code .= "            \$hit = self::STATIC_TABLES[\$method][\$requestTarget];\n";
            $code .= "            return is_int(\$hit)\n";
            $code .= "                ? new MatchResult(status: MatchStatus::Found, routeId: \$hit)\n";
            $code .= "                : new MatchResult(status: MatchStatus::Found, routeId: \$hit[0], params: \$hit[1]);\n";
            $code .= "        }\n\n";
        }
        $code .= <<<'PHP'
        if ($requestTarget === '/') {
            $segments = [];
            $rawPath = '/';
        } elseif (
            $requestTarget !== ''
            && $requestTarget[0] === '/'
            && ctype_print($requestTarget)
            && strpbrk($requestTarget, '%?#\\') === false
            && !str_contains($requestTarget, '/.')
        ) {
            $segments = explode('/', substr($requestTarget, 1));
            $rawPath = $requestTarget;
        } else {
            try {
                $segments = PathNormalizer::normalize($requestTarget);
            } catch (InvalidRequestTargetException $e) {
                return new MatchResult(
                    status: MatchStatus::InvalidRequestTarget,
                    errorReason: $e->getMessage(),
                );
            }
            $rawPath = $segments === [] ? '/' : '/' . implode('/', $segments);
        }

        $segCount = count($segments);
PHP;
        if ($hasStatic) {
            $code .= "\n        if (\$rawPath !== \$requestTarget && isset(self::STATIC_TABLES[\$method][\$rawPath])) {\n";
            $code .= "            \$hit = self::STATIC_TABLES[\$method][\$rawPath];\n";
            $code .= "            return is_int(\$hit)\n";
            $code .= "                ? new MatchResult(status: MatchStatus::Found, routeId: \$hit)\n";
            $code .= "                : new MatchResult(status: MatchStatus::Found, routeId: \$hit[0], params: \$hit[1]);\n";
            $code .= "        }\n";
        }

        if ($activeMethods !== []) {
            $code .= "\n        \$direct = match (\$method) {\n";
            foreach ($activeMethods as $m) {
                $fnName = $this->methodFunctionName($m);
                $mExport = var_export($m, true);
                $code .= "            {$mExport} => \$this->{$fnName}(\$segments, \$segCount),\n";
            }
            $code .= "            default => null,\n";
            $code .= "        };\n";
            $code .= "        if (\$direct !== null) {\n            return \$direct;\n        }\n";
        }

        $hasGetStatic = isset($module->staticTables['GET']);
        $hasGetDynamic = in_array('GET', $activeMethods, true);
        if ($hasGetStatic || $hasGetDynamic) {
            $code .= "\n        if (\$method === 'HEAD') {\n";
            $code .= "            \$getMatch = \$this->dispatchMethod('GET', \$segments, \$segCount, \$rawPath);\n";
            $code .= "            if (\$getMatch !== null) {\n";
            $code .= "                return new MatchResult(\n";
            $code .= "                    status: MatchStatus::Found,\n";
            $code .= "                    routeId: \$getMatch->routeId,\n";
            $code .= "                    params: \$getMatch->params,\n";
            $code .= "                    headFallback: true,\n";
            $code .= "                );\n";
            $code .= "            }\n";
            $code .= "        }\n";
        }

        $code .= <<<'PHP'

        $allowed = $this->collectAllowedMethods($segments, $segCount, $rawPath);
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

PHP;

        // Emit dispatchMethod()
        $code .= "\n    /**\n     * @param list<string> \$segments\n     */\n";
        $code .= "    private function dispatchMethod(string \$method, array \$segments, int \$segCount, string \$rawPath): ?MatchResult\n    {\n";
        if ($hasStatic) {
            $code .= "        if (isset(self::STATIC_TABLES[\$method][\$rawPath])) {\n";
            $code .= "            \$hit = self::STATIC_TABLES[\$method][\$rawPath];\n";
            $code .= "            return is_int(\$hit)\n";
            $code .= "                ? new MatchResult(status: MatchStatus::Found, routeId: \$hit)\n";
            $code .= "                : new MatchResult(status: MatchStatus::Found, routeId: \$hit[0], params: \$hit[1]);\n";
            $code .= "        }\n\n";
        }
        if ($activeMethods !== []) {
            $code .= "        return match (\$method) {\n";
            foreach ($activeMethods as $m) {
                $fnName = $this->methodFunctionName($m);
                $mExport = var_export($m, true);
                $code .= "            {$mExport} => \$this->{$fnName}(\$segments, \$segCount),\n";
            }
            $code .= "            default => null,\n";
            $code .= "        };\n";
        } else {
            $code .= "        return null;\n";
        }
        $code .= "    }\n\n";

        // Emit collectAllowedMethods() using bitwise method mask
        $code .= "    /**\n     * @param list<string> \$segments\n     * @return list<string>\n     */\n";
        $code .= "    private function collectAllowedMethods(array \$segments, int \$segCount, string \$rawPath): array\n    {\n";
        if ($hasStatic) {
            $code .= "        \$stdMask = self::STATIC_METHOD_MASKS[\$rawPath] ?? 0;\n";
        } else {
            $code .= "        \$stdMask = 0;\n";
        }

        foreach ($activeMethods as $m) {
            if (isset(self::METHOD_BITS[$m])) {
                $bit = self::METHOD_BITS[$m];
                $fnName = $this->methodFunctionName($m);
                $code .= "        if ((\$stdMask & {$bit}) === 0 && \$this->{$fnName}(\$segments, \$segCount) !== null) {\n";
                $code .= "            \$stdMask |= {$bit};\n";
                $code .= "        }\n";
            }
        }

        if ($customMethods !== []) {
            $code .= "        \$extra = [];\n";
            $code .= "        foreach (self::CUSTOM_METHODS as \$m) {\n";
            $code .= "            if (\$this->dispatchMethod(\$m, \$segments, \$segCount, \$rawPath) !== null) {\n";
            $code .= "                \$extra[] = \$m;\n";
            $code .= "            }\n";
            $code .= "        }\n";
            $code .= "        if (\$stdMask === 0 && \$extra === []) {\n            return [];\n        }\n";
        } else {
            $code .= "        if (\$stdMask === 0) {\n            return [];\n        }\n";
        }

        $code .= "        if ((\$stdMask & 1) !== 0) {\n            \$stdMask |= 2;\n        }\n";
        $code .= "        \$stdMask |= 64;\n\n";
        $code .= "        \$ordered = [];\n";
        foreach (self::METHOD_BITS as $name => $bit) {
            $code .= "        if ((\$stdMask & {$bit}) !== 0) {\n            \$ordered[] = '{$name}';\n        }\n";
        }
        if ($customMethods !== []) {
            $code .= "        if (\$extra !== []) {\n";
            $code .= "            sort(\$extra);\n";
            $code .= "            foreach (\$extra as \$m) {\n                \$ordered[] = \$m;\n            }\n";
            $code .= "        }\n";
        }
        $code .= "        return \$ordered;\n    }\n";

        foreach ($activeMethods as $method) {
            $code .= $this->emitFunction($method, $module->functions[$method]);
        }

        $code .= "};\n";

        return $code;
    }

    private function isEmptyFunction(LIRFunction $fn): bool
    {
        if (count($fn->blocks) !== 1 || !isset($fn->blocks[$fn->entryLabel])) {
            return false;
        }
        $instrs = $fn->blocks[$fn->entryLabel]->instructions;

        return count($instrs) === 1 && $instrs[0] instanceof ReturnMiss;
    }

    private function isReturnMissBlock(LIRFunction $fn, string $label): bool
    {
        if (!isset($fn->blocks[$label])) {
            return false;
        }
        $instrs = $fn->blocks[$label]->instructions;

        return count($instrs) === 1 && $instrs[0] instanceof ReturnMiss;
    }

    /**
     * Emit a private match_<METHOD>() function from an LIRFunction.
     *
     * @param string $method HTTP method name.
     * @param LIRFunction $fn LIR function.
     *
     * @return string Generated PHP method code.
     *
     * @throws LogicException When an unknown LIR instruction is encountered.
     */
    private function emitFunction(string $method, LIRFunction $fn): string
    {
        $fnName = $this->methodFunctionName($method);
        $code = "\n    /** @param list<string> \$segments */\n";
        $code .= "    private function {$fnName}(array \$segments, int \$segCount): ?MatchResult\n    {\n";

        $segmentCounts = $this->specializedSegmentCounts($fn);
        if ($segmentCounts !== []) {
            if ($this->needsCaptureAccumulator($fn, true)) {
                $code .= "        \$captures = [];\n";
            }
            $code .= "        switch (\$segCount) {\n";
            foreach ($segmentCounts as $segmentCount) {
                $code .= "            case {$segmentCount}: goto sc{$segmentCount}_{$fn->entryLabel};\n";
            }
            $code .= "            default: return null;\n";
            $code .= "        }\n\n";

            foreach ($segmentCounts as $segmentCount) {
                $code .= $this->emitFunctionBlocks(
                    fn: $fn,
                    knownSegmentCount: $segmentCount,
                    labelPrefix: "sc{$segmentCount}_",
                    reachableLabels: $this->reachableLabels($fn, $segmentCount),
                );
            }
        } else {
            $segMask = $this->computeSegmentMask($fn);
            $knownSegCount = null;
            if ($segMask !== null) {
                if (($segMask & ($segMask - 1)) === 0) {
                    $knownSegCount = (int) round(log($segMask, 2));
                    $code .= "        if (\$segCount !== {$knownSegCount}) {\n";
                    $code .= "            return null;\n";
                    $code .= "        }\n";
                } else {
                    $code .= "        if (\$segCount > 62 || (((1 << \$segCount) & {$segMask}) === 0)) {\n";
                    $code .= "            return null;\n";
                    $code .= "        }\n";
                }
            }
            if ($this->needsCaptureAccumulator($fn, $knownSegCount !== null)) {
                $code .= "        \$captures = [];\n";
            }
            $code .= "        goto {$fn->entryLabel};\n\n";
            $code .= $this->emitFunctionBlocks(
                fn: $fn,
                knownSegmentCount: $knownSegCount,
                reachableLabels: $knownSegCount !== null ? $this->reachableLabels($fn, $knownSegCount) : null,
            );
        }

        $code .= "    }\n";

        return $code;
    }

    /**
     * Compute a 63-bit segment count mask when all routes have exact segment counts <= 62 and no regex constraints.
     */
    private function computeSegmentMask(LIRFunction $fn): ?int
    {
        $mask = 0;
        foreach ($fn->blocks as $block) {
            foreach ($block->instructions as $instr) {
                if ($instr instanceof CaptureWildcard) {
                    return null;
                }
                if ($instr instanceof GuardConstraint && $instr->constraint->kind === 'regex') {
                    return null;
                }
                if ($instr instanceof BranchEOF && $instr->segmentCount >= 0) {
                    if ($instr->segmentCount > 62) {
                        return null;
                    }
                    $mask |= (1 << $instr->segmentCount);
                }
            }
        }

        return $mask !== 0 ? $mask : null;
    }

    /**
     * Emit the selected basic blocks for a generated matcher method, fusing linear candidate chains.
     *
     * @param LIRFunction $fn Function whose blocks are emitted.
     * @param int|null $knownSegmentCount Compile-time segment count for a specialized lane.
     * @param string $labelPrefix Prefix applied to labels in a specialized lane.
     * @param array<string, true>|null $reachableLabels Reachable block set, or null for every block.
     *
     * @return string Generated PHP basic blocks.
     *
     * @throws LogicException When an unknown LIR instruction is encountered.
     */
    private function emitFunctionBlocks(
        LIRFunction $fn,
        ?int $knownSegmentCount = null,
        string $labelPrefix = '',
        ?array $reachableLabels = null,
    ): string {
        $labels = array_values(array_filter(
            array_keys($fn->blocks),
            static fn (string $label): bool => $reachableLabels === null || isset($reachableLabels[$label]),
        ));

        $inDegree = $this->computeInDegrees($fn, $labels);

        // Pre-fuse linear candidate chains into single statements.
        /** @var array<string, string> $fusedCode */
        $fusedCode = [];
        /** @var array<string, true> $consumed */
        $consumed = [];

        foreach ($labels as $label) {
            if (isset($consumed[$label])) {
                continue;
            }
            $fused = $this->tryFuseLinearChain($fn, $label, $inDegree, $knownSegmentCount);
            if ($fused !== null) {
                $fusedCode[$label] = $fused['code'];
                foreach ($fused['consumed'] as $cLabel) {
                    $consumed[$cLabel] = true;
                }
            }
        }

        $keptLabels = array_values(array_filter(
            $labels,
            static fn (string $label): bool => !isset($consumed[$label]),
        ));

        $code = '';
        $count = count($keptLabels);
        for ($idx = 0; $idx < $count; $idx++) {
            $label = $keptLabels[$idx];
            $nextLabel = $keptLabels[$idx + 1] ?? null;
            $code .= "        {$labelPrefix}{$label}:\n";

            if (isset($fusedCode[$label])) {
                $failTarget = $this->extractFusedFailTarget($fn, $label, $inDegree, $knownSegmentCount);
                $code .= $fusedCode[$label];
                if ($failTarget !== null) {
                    if ($this->isReturnMissBlock($fn, $failTarget)) {
                        $code .= "        return null;\n";
                    } elseif ($failTarget !== $nextLabel) {
                        $code .= "        goto {$labelPrefix}{$failTarget};\n";
                    }
                }
                $code .= "\n";
                continue;
            }

            $instrs = $fn->blocks[$label]->instructions;
            $folded = $this->tryEmitFoldedReturnMatch($instrs);
            if ($folded !== null) {
                $code .= $folded . "\n";
                continue;
            }

            foreach ($instrs as $instr) {
                $code .= $this->emitInstruction(
                    instr: $instr,
                    fallthroughLabel: $nextLabel,
                    knownSegmentCount: $knownSegmentCount,
                    labelPrefix: $labelPrefix,
                );
            }
            $code .= "\n";
        }

        return $code;
    }

    /**
     * Compute incoming edge counts for the active blocks in a function.
     *
     * @param list<string> $labels
     *
     * @return array<string, int>
     */
    private function computeInDegrees(LIRFunction $fn, array $labels): array
    {
        $inSet = array_flip($labels);
        $inDegree = array_fill_keys($labels, 0);
        if (isset($inDegree[$fn->entryLabel])) {
            $inDegree[$fn->entryLabel] = 1;
        }

        foreach ($labels as $label) {
            foreach ($fn->blocks[$label]->instructions as $instr) {
                foreach ($instr->targets() as $target) {
                    if (isset($inSet[$target])) {
                        $inDegree[$target]++;
                    }
                }
            }
        }

        return $inDegree;
    }

    /**
     * Try to fuse a linear chain of single-predecessor blocks into a single conditional ReturnMatch.
     *
     * @param array<string, int> $inDegree
     *
     * @return ?array{code: string, consumed: list<string>, failTarget: string}
     */
    private function tryFuseLinearChain(
        LIRFunction $fn,
        string $startLabel,
        array $inDegree,
        ?int $knownSegmentCount,
    ): ?array {
        $curLabel = $startLabel;
        $visited = [];
        $consumed = [];
        $varMap = [];
        $litConds = [];
        $otherConds = [];
        $orderedConds = [];
        $captures = [];
        $hasRegex = false;
        $exactSegCount = null;
        $commonFail = null;

        while (true) {
            if (isset($visited[$curLabel]) || !isset($fn->blocks[$curLabel])) {
                return null;
            }
            if ($curLabel !== $startLabel && ($inDegree[$curLabel] ?? 0) !== 1) {
                return null;
            }

            $visited[$curLabel] = true;
            if ($curLabel !== $startLabel) {
                $consumed[] = $curLabel;
            }

            $instrs = $fn->blocks[$curLabel]->instructions;
            $nextLabel = null;

            foreach ($instrs as $instr) {
                if ($instr instanceof ResetCaptures) {
                    $captures = [];
                    continue;
                }

                if ($instr instanceof ReadSegment) {
                    $segExpr = "\$segments[{$instr->index}]";
                    $varMap[$instr->targetVar] = $segExpr;
                    if ($knownSegmentCount !== null) {
                        if ($instr->index >= $knownSegmentCount) {
                            return null;
                        }
                        $nextLabel = $instr->onPresent;
                        break;
                    }
                    if ($commonFail !== null && $commonFail !== $instr->onMissing) {
                        return null;
                    }
                    $commonFail = $instr->onMissing;
                    $orderedConds[] = ['type' => 'isset', 'expr' => "isset({$segExpr})"];
                    $nextLabel = $instr->onPresent;
                    break;
                }

                if ($instr instanceof GuardLiteral) {
                    if ($commonFail !== null && $commonFail !== $instr->onFail) {
                        return null;
                    }
                    $commonFail = $instr->onFail;
                    $expr = $varMap[$instr->var] ?? $instr->var;
                    $cond = $expr . ' === ' . var_export($instr->expected, true);
                    $litConds[] = $cond;
                    $orderedConds[] = ['type' => 'cond', 'expr' => $cond];
                    $nextLabel = $instr->onMatch;
                    break;
                }

                if ($instr instanceof GuardConstraint) {
                    if ($commonFail !== null && $commonFail !== $instr->onFail) {
                        return null;
                    }
                    $commonFail = $instr->onFail;
                    if ($instr->constraint->kind === 'regex') {
                        $hasRegex = true;
                    }
                    $expr = $varMap[$instr->var] ?? $instr->var;
                    $cond = $this->emitConstraintCondition($expr, $instr->constraint);
                    $otherConds[] = $cond;
                    $orderedConds[] = ['type' => 'cond', 'expr' => $cond];
                    $nextLabel = $instr->onMatch;
                    break;
                }

                if ($instr instanceof Capture) {
                    $captures[$instr->paramName] = $varMap[$instr->sourceVar] ?? $instr->sourceVar;
                    continue;
                }

                if ($instr instanceof BranchEOF) {
                    if ($knownSegmentCount !== null) {
                        if ($instr->segmentCount !== $knownSegmentCount) {
                            return null;
                        }
                        $nextLabel = $instr->onEof;
                        break;
                    }
                    if ($commonFail !== null && $commonFail !== $instr->onMore) {
                        return null;
                    }
                    $commonFail = $instr->onMore;
                    $exactSegCount = $instr->segmentCount;
                    $orderedConds[] = ['type' => 'eof', 'expr' => "\$segCount === {$instr->segmentCount}"];
                    $nextLabel = $instr->onEof;
                    break;
                }

                if ($instr instanceof Jump) {
                    $nextLabel = $instr->target;
                    break;
                }

                if ($instr instanceof ReturnMatch) {
                    if ($consumed === [] || $commonFail === null) {
                        return null;
                    }

                    $condParts = [];
                    if ($knownSegmentCount === null && $exactSegCount !== null && !$hasRegex) {
                        $condParts[] = "\$segCount === {$exactSegCount}";
                        foreach ($litConds as $c) {
                            $condParts[] = $c;
                        }
                        foreach ($otherConds as $c) {
                            $condParts[] = $c;
                        }
                    } else {
                        foreach ($orderedConds as $item) {
                            $condParts[] = $item['expr'];
                        }
                    }

                    $retStmt = $this->buildReturnMatchStatement($instr->routeId, $captures, $instr->defaults);
                    if ($condParts === []) {
                        return [
                            'code' => $retStmt,
                            'consumed' => $consumed,
                            'failTarget' => $commonFail,
                        ];
                    }

                    $condStr = implode(' && ', $condParts);

                    return [
                        'code' => "        if ({$condStr}) {\n    {$retStmt}        }\n",
                        'consumed' => $consumed,
                        'failTarget' => $commonFail,
                    ];
                }

                return null;
            }

            if ($nextLabel === null) {
                return null;
            }
            $curLabel = $nextLabel;
        }
    }

    /**
     * @param array<string, int> $inDegree
     */
    private function extractFusedFailTarget(
        LIRFunction $fn,
        string $startLabel,
        array $inDegree,
        ?int $knownSegmentCount,
    ): ?string {
        $res = $this->tryFuseLinearChain($fn, $startLabel, $inDegree, $knownSegmentCount);

        return $res['failTarget'] ?? null;
    }

    /**
     * @param array<string, string> $captures
     * @param array<string, string> $defaults
     */
    private function buildReturnMatchStatement(int $routeId, array $captures, array $defaults): string
    {
        if ($captures === [] && $defaults === []) {
            return sprintf("        return new MatchResult(status: MatchStatus::Found, routeId: %d);\n", $routeId);
        }

        $pairs = [];
        foreach ($captures as $k => $expr) {
            $pairs[] = var_export($k, true) . ' => ' . $expr;
        }
        $paramsExpr = '[' . implode(', ', $pairs) . ']';
        if ($defaults !== []) {
            $defExpr = CompactPhpExporter::export($defaults);
            $paramsExpr = $captures === [] ? $defExpr : "{$paramsExpr} + {$defExpr}";
        }

        return sprintf(
            "        return new MatchResult(status: MatchStatus::Found, routeId: %d, params: %s);\n",
            $routeId,
            $paramsExpr,
        );
    }

    /**
     * Determine whether a matcher function still needs a mutable capture array.
     *
     * @param LIRFunction $fn Function to inspect.
     * @param bool $specialized Whether segment-count specialization is active.
     *
     * @return bool True when at least one non-folded/non-fused block uses the capture accumulator.
     */
    private function needsCaptureAccumulator(LIRFunction $fn, bool $specialized): bool
    {
        $labels = array_keys($fn->blocks);
        $inDegree = $this->computeInDegrees($fn, $labels);
        $consumed = [];

        if (!$specialized) {
            foreach ($labels as $label) {
                if (isset($consumed[$label])) {
                    continue;
                }
                $fused = $this->tryFuseLinearChain($fn, $label, $inDegree, null);
                if ($fused !== null) {
                    $consumed[$label] = true;
                    foreach ($fused['consumed'] as $c) {
                        $consumed[$c] = true;
                    }
                }
            }
        }

        foreach ($fn->blocks as $label => $block) {
            if (isset($consumed[$label])) {
                continue;
            }
            if ($this->tryEmitFoldedReturnMatch($block->instructions) !== null) {
                continue;
            }

            foreach ($block->instructions as $instr) {
                if ($instr instanceof CaptureWildcard) {
                    return true;
                }
            }
        }

        // Fallback: check if any non-fused ReturnMatch/ResetCaptures/Capture remains
        foreach ($fn->blocks as $label => $block) {
            if (isset($consumed[$label])) {
                continue;
            }
            if ($this->tryEmitFoldedReturnMatch($block->instructions) !== null) {
                continue;
            }
            foreach ($block->instructions as $instr) {
                if (
                    $instr instanceof ResetCaptures
                    || $instr instanceof Capture
                    || $instr instanceof ReturnMatch
                ) {
                    // Only needed if not inside a specialized fused chain
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Select exact segment counts suitable for O2 partial evaluation.
     *
     * @param LIRFunction $fn Function to inspect.
     *
     * @return list<int> Sorted exact segment counts, or an empty list when specialization is unsafe.
     */
    private function specializedSegmentCounts(LIRFunction $fn): array
    {
        if (!$fn->specializeSegmentCounts || count($fn->blocks) > self::MAX_SPECIALIZED_BLOCKS) {
            return [];
        }

        $counts = [];
        foreach ($fn->blocks as $block) {
            foreach ($block->instructions as $instr) {
                if ($instr instanceof CaptureWildcard) {
                    return [];
                }
                if ($instr instanceof BranchEOF && $instr->segmentCount >= 0) {
                    $counts[$instr->segmentCount] = true;
                }
            }
        }

        if ($counts === [] || count($counts) > self::MAX_SPECIALIZED_SEGMENT_COUNTS) {
            return [];
        }

        $segmentCounts = array_keys($counts);
        sort($segmentCounts);

        return $segmentCounts;
    }

    /**
     * Resolve blocks reachable when the request segment count is known at compile time.
     *
     * @param LIRFunction $fn Function to traverse.
     * @param int $segmentCount Specialized request segment count.
     *
     * @return array<string, true> Reachable block labels.
     */
    private function reachableLabels(LIRFunction $fn, int $segmentCount): array
    {
        $reachable = [];
        $pending = [$fn->entryLabel];

        while ($pending !== []) {
            $label = array_pop($pending);
            if (isset($reachable[$label]) || !isset($fn->blocks[$label])) {
                continue;
            }

            $reachable[$label] = true;
            foreach ($fn->blocks[$label]->instructions as $instr) {
                $targets = match (true) {
                    $instr instanceof ReadSegment => [
                        $instr->index < $segmentCount ? $instr->onPresent : $instr->onMissing,
                    ],
                    $instr instanceof BranchEOF => [
                        $instr->segmentCount === $segmentCount ? $instr->onEof : $instr->onMore,
                    ],
                    $instr instanceof GuardLiteral,
                    $instr instanceof GuardConstraint => [$instr->onMatch, $instr->onFail],
                    $instr instanceof SwitchLiteral => [...array_values($instr->cases), $instr->default],
                    $instr instanceof Jump => [$instr->target],
                    default => [],
                };

                if ($targets !== []) {
                    array_push($pending, ...$targets);
                    break;
                }

                if ($instr instanceof ReturnMatch || $instr instanceof ReturnMiss) {
                    break;
                }
            }
        }

        return $reachable;
    }

    /**
     * Fold `[ResetCaptures, Capture..., ReturnMatch]` into a single literal-array MatchResult return.
     *
     * @param list<object> $instrs Block instructions.
     *
     * @return ?string Folded PHP return statement, or null if not applicable.
     */
    private function tryEmitFoldedReturnMatch(array $instrs): ?string
    {
        $count = count($instrs);
        if ($count < 2 || !($instrs[0] instanceof ResetCaptures) || !($instrs[$count - 1] instanceof ReturnMatch)) {
            return null;
        }

        $pairs = [];
        for ($i = 1; $i < $count - 1; $i++) {
            $instr = $instrs[$i];
            if (!$instr instanceof Capture) {
                return null;
            }
            $pairs[] = var_export($instr->paramName, true) . ' => ' . $instr->sourceVar;
        }

        /** @var ReturnMatch $ret */
        $ret = $instrs[$count - 1];
        if ($pairs === [] && $ret->defaults === []) {
            return sprintf("        return new MatchResult(status: MatchStatus::Found, routeId: %d);\n", $ret->routeId);
        }

        $paramsExpr = '[' . implode(', ', $pairs) . ']';
        if ($ret->defaults !== []) {
            $defExpr = CompactPhpExporter::export($ret->defaults);
            $paramsExpr = $pairs === [] ? $defExpr : "{$paramsExpr} + {$defExpr}";
        }

        return sprintf(
            "        return new MatchResult(status: MatchStatus::Found, routeId: %d, params: %s);\n",
            $ret->routeId,
            $paramsExpr,
        );
    }

    /**
     * Emit PHP code for a single LIR instruction.
     *
     * @param object $instr LIR instruction instance.
     * @param ?string $fallthroughLabel Immediately following block label, if any.
     *
     * @return string Generated PHP statements.
     *
     * @throws LogicException When an unknown LIR instruction is encountered.
     */
    private function emitInstruction(
        object $instr,
        ?string $fallthroughLabel = null,
        ?int $knownSegmentCount = null,
        string $labelPrefix = '',
    ): string {
        if ($instr instanceof ResetCaptures) {
            return "        \$captures = [];\n";
        }

        if ($instr instanceof ReadSegment) {
            if ($knownSegmentCount !== null) {
                if ($instr->index >= $knownSegmentCount) {
                    return $instr->onMissing === $fallthroughLabel
                        ? ''
                        : "        goto {$labelPrefix}{$instr->onMissing};\n";
                }

                $jump = $instr->onPresent === $fallthroughLabel
                    ? ''
                    : "        goto {$labelPrefix}{$instr->onPresent};\n";

                return sprintf("        %s = \$segments[%d];\n%s", $instr->targetVar, $instr->index, $jump);
            }

            $jump = $instr->onPresent === $fallthroughLabel ? '' : "        goto {$labelPrefix}{$instr->onPresent};\n";

            return sprintf(
                "        if (!isset(\$segments[%d])) { goto %s%s; }\n        %s = \$segments[%d];\n%s",
                $instr->index,
                $labelPrefix,
                $instr->onMissing,
                $instr->targetVar,
                $instr->index,
                $jump,
            );
        }

        if ($instr instanceof GuardLiteral) {
            $exp = var_export($instr->expected, true);
            $jump = $instr->onFail === $fallthroughLabel ? '' : "        goto {$labelPrefix}{$instr->onFail};\n";

            return sprintf(
                "        if (%s === %s) { goto %s%s; }\n%s",
                $instr->var,
                $exp,
                $labelPrefix,
                $instr->onMatch,
                $jump,
            );
        }

        if ($instr instanceof SwitchLiteral) {
            $code = "        switch ({$instr->var}) {\n";
            foreach ($instr->cases as $literal => $target) {
                $exp = var_export((string) $literal, true);
                $code .= "            case {$exp}: goto {$labelPrefix}{$target};\n";
            }
            $code .= "            default: goto {$labelPrefix}{$instr->default};\n";
            $code .= "        }\n";

            return $code;
        }

        if ($instr instanceof GuardConstraint) {
            $cond = $this->emitConstraintCondition($instr->var, $instr->constraint);
            $jumpFail = $instr->onFail === $fallthroughLabel ? '' : "        goto {$labelPrefix}{$instr->onFail};\n";

            return sprintf(
                "        if (%s) { goto %s%s; }\n%s",
                $cond,
                $labelPrefix,
                $instr->onMatch,
                $jumpFail,
            );
        }

        if ($instr instanceof Capture) {
            $name = var_export($instr->paramName, true);

            return sprintf("        \$captures[%s] = %s;\n", $name, $instr->sourceVar);
        }

        if ($instr instanceof CaptureWildcard) {
            $name = var_export($instr->paramName, true);

            return sprintf(
                "        \$wcParts = array_slice(\$segments, %d);\n        foreach (\$wcParts as \$wcPart) { if (\$wcPart === '') { return null; } }\n        \$captures[%s] = implode('/', \$wcParts);\n",
                $instr->startIndex,
                $name,
            );
        }

        if ($instr instanceof BranchEOF) {
            if ($knownSegmentCount !== null) {
                $target = $instr->segmentCount === $knownSegmentCount ? $instr->onEof : $instr->onMore;

                return $target === $fallthroughLabel ? '' : "        goto {$labelPrefix}{$target};\n";
            }

            $jumpMore = $instr->onMore === $fallthroughLabel ? '' : "        goto {$labelPrefix}{$instr->onMore};\n";

            return sprintf(
                "        if (\$segCount === %d) { goto %s%s; }\n%s",
                $instr->segmentCount,
                $labelPrefix,
                $instr->onEof,
                $jumpMore,
            );
        }

        if ($instr instanceof Jump) {
            return $instr->target === $fallthroughLabel ? '' : sprintf("        goto %s%s;\n", $labelPrefix, $instr->target);
        }

        if ($instr instanceof ReturnMatch) {
            if ($instr->defaults === []) {
                return sprintf("        return new MatchResult(status: MatchStatus::Found, routeId: %d, params: \$captures);\n", $instr->routeId);
            }
            $defExport = CompactPhpExporter::export($instr->defaults);

            return sprintf(
                "        return new MatchResult(status: MatchStatus::Found, routeId: %d, params: \$captures + %s);\n",
                $instr->routeId,
                $defExport,
            );
        }

        if ($instr instanceof ReturnMiss) {
            return "        return null;\n";
        }

        throw new LogicException('Unknown LIR instruction: ' . $instr::class);
    }

    private function emitConstraintCondition(string $var, NormalizedConstraint $c): string
    {
        return match ($c->kind) {
            'none' => "{$var} !== ''",
            'builtin' => match ($c->builtinName) {
                'int' => "(!isset({$var}[4096]) && ctype_digit({$var}))",
                'alpha' => "(!isset({$var}[4096]) && ctype_alpha({$var}))",
                'alnum' => "(!isset({$var}[4096]) && ctype_alnum({$var}))",
                'slug' => "({$var} !== '' && !isset({$var}[4096]) && preg_match('/^[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*$/', {$var}) === 1)",
                'uuid' => "(strlen({$var}) === 36 && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', {$var}) === 1)",
                default => sprintf('ConstraintRegistry::matchesBuiltin(%s, %s)', var_export($c->builtinName ?? '', true), $var),
            },
            'in' => sprintf('ConstraintRegistry::matchesIn(%s, %s)', CompactPhpExporter::export($c->allowedValues), $var),
            'regex' => sprintf('ConstraintRegistry::matchesRegex(%s, %s)', var_export($c->compiledRegex ?? '', true), $var),
            default => 'false',
        };
    }

    private function methodFunctionName(string $method): string
    {
        return 'match_' . preg_replace('/[^A-Za-z0-9_]/', '_', $method);
    }
}
