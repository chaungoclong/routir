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

    /** @var list<string> */
    private const array METHOD_ORDER = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

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
        $methodsExport = var_export($methods, true);

        $code = "<?php\n\ndeclare(strict_types=1);\n\n";
        $code .= "use Routir\\Contracts\\MatcherInterface;\n";
        $code .= "use Routir\\Contracts\\MatchResult;\n";
        $code .= "use Routir\\Contracts\\MatchStatus;\n";
        $code .= "use Routir\\Exception\\InvalidRequestTargetException;\n";
        $code .= "use Routir\\Runtime\\ConstraintRegistry;\n";
        $code .= "use Routir\\Runtime\\PathNormalizer;\n\n";
        $code .= "return new class implements MatcherInterface {\n";
        $code .= "    /** @var list<string> */\n";
        $code .= "    private const array REGISTERED_METHODS = {$methodsExport};\n\n";
        $code .= "    /** @var list<string> */\n";
        $code .= "    private const array METHOD_ORDER = " . var_export(self::METHOD_ORDER, true) . ";\n\n";

        // Emit static tables if present (O1/O2)
        if ($module->staticTables !== []) {
            $code .= "    /** @var array<string, array<string, array{id: int, defaults: array<string, string>}>> */\n";
            $code .= "    private const array STATIC_TABLES = " . var_export($module->staticTables, true) . ";\n\n";
        }

        // Emit match() entrypoint
        $code .= "    public function match(string \$method, string \$requestTarget): MatchResult\n    {\n";
        if ($module->staticTables !== []) {
            $code .= "        if (isset(self::STATIC_TABLES[\$method][\$requestTarget]) && !str_contains(\$requestTarget, '%')) {\n";
            $code .= "            \$hit = self::STATIC_TABLES[\$method][\$requestTarget];\n";
            $code .= "            return new MatchResult(status: MatchStatus::Found, routeId: \$hit['id'], params: \$hit['defaults']);\n";
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
        if ($module->staticTables !== []) {
            $code .= "\n        if (\$rawPath !== \$requestTarget && isset(self::STATIC_TABLES[\$method][\$rawPath])) {\n";
            $code .= "            \$hit = self::STATIC_TABLES[\$method][\$rawPath];\n";
            $code .= "            return new MatchResult(status: MatchStatus::Found, routeId: \$hit['id'], params: \$hit['defaults']);\n";
            $code .= "        }\n";
        }
        $code .= "\n        \$direct = match (\$method) {\n";
        foreach ($methods as $m) {
            $fnName = $this->methodFunctionName($m);
            $mExport = var_export($m, true);
            $code .= "            {$mExport} => \$this->{$fnName}(\$segments, \$segCount),\n";
        }
        $code .= "            default => null,\n";
        $code .= "        };\n";
        $code .= <<<'PHP'
        if ($direct !== null) {
            return $direct;
        }

        if ($method === 'HEAD') {
            $getMatch = $this->dispatchMethod('GET', $segments, $segCount, $rawPath);
            if ($getMatch !== null) {
                return new MatchResult(
                    status: MatchStatus::Found,
                    routeId: $getMatch->routeId,
                    params: $getMatch->params,
                    headFallback: true,
                );
            }
        }

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
        if ($module->staticTables !== []) {
            $code .= "        if (isset(self::STATIC_TABLES[\$method][\$rawPath])) {\n";
            $code .= "            \$hit = self::STATIC_TABLES[\$method][\$rawPath];\n";
            $code .= "            return new MatchResult(status: MatchStatus::Found, routeId: \$hit['id'], params: \$hit['defaults']);\n";
            $code .= "        }\n\n";
        }
        $code .= "        return match (\$method) {\n";
        foreach ($methods as $m) {
            $fnName = $this->methodFunctionName($m);
            $mExport = var_export($m, true);
            $code .= "            {$mExport} => \$this->{$fnName}(\$segments, \$segCount),\n";
        }
        $code .= "            default => null,\n";
        $code .= "        };\n";
        $code .= "    }\n\n";

        // Emit collectAllowedMethods()
        $code .= <<<'PHP'
    /**
     * @param list<string> $segments
     * @return list<string>
     */
    private function collectAllowedMethods(array $segments, int $segCount, string $rawPath): array
    {
        $matched = [];
        foreach (self::REGISTERED_METHODS as $m) {
            if ($this->dispatchMethod($m, $segments, $segCount, $rawPath) !== null) {
                $matched[$m] = true;
            }
        }

        if ($matched === []) {
            return [];
        }

        if (isset($matched['GET'])) {
            $matched['HEAD'] = true;
        }
        $matched['OPTIONS'] = true;

        $ordered = [];
        foreach (self::METHOD_ORDER as $std) {
            if (isset($matched[$std])) {
                $ordered[] = $std;
                unset($matched[$std]);
            }
        }

        if ($matched !== []) {
            $extra = array_keys($matched);
            sort($extra);
            foreach ($extra as $m) {
                $ordered[] = $m;
            }
        }

        return $ordered;
    }

PHP;

        foreach ($module->functions as $method => $fn) {
            $code .= $this->emitFunction($method, $fn);
        }

        $code .= "};\n";

        return $code;
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
        $code = "\n    /**\n     * @param list<string> \$segments\n     */\n";
        $code .= "    private function {$fnName}(array \$segments, int \$segCount): ?MatchResult\n    {\n";

        if ($this->needsCaptureAccumulator($fn)) {
            $code .= "        \$captures = [];\n";
        }

        $segmentCounts = $this->specializedSegmentCounts($fn);
        if ($segmentCounts !== []) {
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
            $code .= "        goto {$fn->entryLabel};\n\n";
            $code .= $this->emitFunctionBlocks($fn);
        }

        $code .= "    }\n";

        return $code;
    }

    /**
     * Emit the selected basic blocks for a generated matcher method.
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
        $code = '';
        $labels = array_values(array_filter(
            array_keys($fn->blocks),
            static fn (string $label): bool => $reachableLabels === null || isset($reachableLabels[$label]),
        ));

        foreach ($labels as $idx => $label) {
            $block = $fn->blocks[$label];
            $nextLabel = $labels[$idx + 1] ?? null;
            $code .= "        {$labelPrefix}{$label}:\n";

            $instrs = $block->instructions;
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
     * Determine whether a matcher function still needs a mutable capture array.
     *
     * @param LIRFunction $fn Function to inspect.
     *
     * @return bool True when at least one non-folded block uses the capture accumulator.
     */
    private function needsCaptureAccumulator(LIRFunction $fn): bool
    {
        foreach ($fn->blocks as $block) {
            if ($this->tryEmitFoldedReturnMatch($block->instructions) !== null) {
                continue;
            }

            foreach ($block->instructions as $instr) {
                if (
                    $instr instanceof ResetCaptures
                    || $instr instanceof Capture
                    || $instr instanceof CaptureWildcard
                    || $instr instanceof ReturnMatch
                ) {
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
        $paramsExpr = '[' . implode(', ', $pairs) . ']';
        if ($ret->defaults !== []) {
            $paramsExpr .= ' + ' . var_export($ret->defaults, true);
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
    ): string
    {
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

            return sprintf(
                "        if (%s) { goto %s%s; }\n        goto %s%s;\n",
                $cond,
                $labelPrefix,
                $instr->onMatch,
                $labelPrefix,
                $instr->onFail,
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

            return sprintf(
                "        if (\$segCount === %d) { goto %s%s; }\n        goto %s%s;\n",
                $instr->segmentCount,
                $labelPrefix,
                $instr->onEof,
                $labelPrefix,
                $instr->onMore,
            );
        }

        if ($instr instanceof Jump) {
            return sprintf("        goto %s%s;\n", $labelPrefix, $instr->target);
        }

        if ($instr instanceof ReturnMatch) {
            if ($instr->defaults === []) {
                return sprintf("        return new MatchResult(status: MatchStatus::Found, routeId: %d, params: \$captures);\n", $instr->routeId);
            }
            $defExport = var_export($instr->defaults, true);

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
                'int' => "({$var} !== '' && strlen({$var}) <= 4096 && ctype_digit({$var}))",
                'alpha' => "({$var} !== '' && strlen({$var}) <= 4096 && ctype_alpha({$var}))",
                'alnum' => "({$var} !== '' && strlen({$var}) <= 4096 && ctype_alnum({$var}))",
                'slug' => "({$var} !== '' && strlen({$var}) <= 4096 && preg_match('/^[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*$/', {$var}) === 1)",
                'uuid' => "({$var} !== '' && strlen({$var}) <= 4096 && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', {$var}) === 1)",
                default => sprintf('ConstraintRegistry::matchesBuiltin(%s, %s)', var_export($c->builtinName ?? '', true), $var),
            },
            'in' => sprintf('ConstraintRegistry::matchesIn(%s, %s)', var_export($c->allowedValues, true), $var),
            'regex' => sprintf('ConstraintRegistry::matchesRegex(%s, %s)', var_export($c->compiledRegex ?? '', true), $var),
            default => 'false',
        };
    }

    private function methodFunctionName(string $method): string
    {
        return 'match_' . preg_replace('/[^A-Za-z0-9_]/', '_', $method);
    }
}
