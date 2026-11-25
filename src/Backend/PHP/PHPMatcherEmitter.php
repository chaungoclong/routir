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
            && strlen($requestTarget) === strspn($requestTarget, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789/_-.~')
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
        $code .= "        \$captures = [];\n";
        $code .= "        goto {$fn->entryLabel};\n\n";

        $labels = array_keys($fn->blocks);
        $labelCount = count($labels);
        foreach ($labels as $idx => $label) {
            $block = $fn->blocks[$label];
            $nextLabel = $labels[$idx + 1] ?? null;
            $code .= "        {$label}:\n";

            $instrs = $block->instructions;
            $folded = $this->tryEmitFoldedReturnMatch($instrs);
            if ($folded !== null) {
                $code .= $folded . "\n";
                continue;
            }

            foreach ($instrs as $instr) {
                $code .= $this->emitInstruction($instr, $nextLabel);
            }
            $code .= "\n";
        }

        $code .= "    }\n";

        return $code;
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
    private function emitInstruction(object $instr, ?string $fallthroughLabel = null): string
    {
        if ($instr instanceof ResetCaptures) {
            return "        \$captures = [];\n";
        }

        if ($instr instanceof ReadSegment) {
            $jump = $instr->onPresent === $fallthroughLabel ? '' : "        goto {$instr->onPresent};\n";

            return sprintf(
                "        if (!isset(\$segments[%d])) { goto %s; }\n        %s = \$segments[%d];\n%s",
                $instr->index,
                $instr->onMissing,
                $instr->targetVar,
                $instr->index,
                $jump,
            );
        }

        if ($instr instanceof GuardLiteral) {
            $exp = var_export($instr->expected, true);
            $jump = $instr->onFail === $fallthroughLabel ? '' : "        goto {$instr->onFail};\n";

            return sprintf(
                "        if (%s === %s) { goto %s; }\n%s",
                $instr->var,
                $exp,
                $instr->onMatch,
                $jump,
            );
        }

        if ($instr instanceof SwitchLiteral) {
            $code = "        switch ({$instr->var}) {\n";
            foreach ($instr->cases as $literal => $target) {
                $exp = var_export((string) $literal, true);
                $code .= "            case {$exp}: goto {$target};\n";
            }
            $code .= "            default: goto {$instr->default};\n";
            $code .= "        }\n";

            return $code;
        }

        if ($instr instanceof GuardConstraint) {
            $cond = $this->emitConstraintCondition($instr->var, $instr->constraint);

            return sprintf(
                "        if (%s) { goto %s; }\n        goto %s;\n",
                $cond,
                $instr->onMatch,
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
            return sprintf(
                "        if (\$segCount === %d) { goto %s; }\n        goto %s;\n",
                $instr->segmentCount,
                $instr->onEof,
                $instr->onMore,
            );
        }

        if ($instr instanceof Jump) {
            return sprintf("        goto %s;\n", $instr->target);
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
