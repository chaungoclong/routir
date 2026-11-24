<?php

declare(strict_types=1);

namespace Routir\Frontend\Handler;

use Closure as NativeClosure;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionException;
use ReflectionFunction;
use ReflectionParameter;
use Routir\Diagnostics\Diagnostic;
use Routir\Diagnostics\Severity;
use Routir\Diagnostics\SourceSpan;
use Routir\Exception\CompilationException;

final class ClosureExtractor
{
    /** @var array<string, list<Node\Stmt>> */
    private array $astCache = [];

    public function __construct(
        private readonly bool $allowScalarCaptures = true,
    ) {
    }

    /**
     * Extract AST source and validated scalar captures for a closure into compilable PHP factory code.
     *
     * @param NativeClosure $closure Closure to extract.
     *
     * @return ExtractedClosure
     *
     * @throws CompilationException When the closure uses unsupported features, captures, or is ambiguous.
     * @throws ReflectionException When reflecting the closure fails.
     */
    public function extract(NativeClosure $closure): ExtractedClosure
    {
        $ref = new ReflectionFunction($closure);
        $file = $ref->getFileName();
        $startLine = $ref->getStartLine();
        $endLine = $ref->getEndLine();

        if ($file === false || $startLine === false || $endLine === false || !is_file($file) || str_contains($file, "eval()'d code")) {
            throw CompilationException::fromDiagnostic(new Diagnostic(
                code: 'RC_CLOSURE_NOT_FILE_BACKED',
                severity: Severity::Error,
                message: 'Closure is not backed by a readable PHP source file (e.g. eval() or dynamic code).',
                span: new SourceSpan(0, 0, is_string($file) ? $file : null, is_int($startLine) ? $startLine : null),
                hint: 'Move the closure into a PHP file or use a controller/invokable class.',
            ));
        }

        $span = new SourceSpan(0, 0, $file, $startLine);

        if ($ref->getClosureThis() !== null) {
            throw CompilationException::fromDiagnostic(new Diagnostic(
                code: 'RC_CLOSURE_BOUND_THIS',
                severity: Severity::Error,
                message: 'Closures bound to $this cannot be compiled.',
                span: $span,
                hint: 'Use a static closure (`static fn` / `static function`) or a controller class.',
            ));
        }

        $stmts = $this->parseFileWithNames($file);
        $finder = new NodeFinder();
        /** @var list<Closure|ArrowFunction> $candidates */
        $candidates = $finder->find($stmts, static function (Node $node) use ($startLine, $endLine): bool {
            if (!$node instanceof Closure && !$node instanceof ArrowFunction) {
                return false;
            }

            return $node->getStartLine() === $startLine && $node->getEndLine() === $endLine;
        });

        // Disambiguate by parameter signature if multiple closures share the same line
        if (count($candidates) > 1) {
            $refParamNames = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $ref->getParameters());
            $filtered = [];
            foreach ($candidates as $cand) {
                $candParams = array_map(
                    static fn (Node\Param $p): string => $p->var instanceof Variable && is_string($p->var->name) ? $p->var->name : '',
                    $cand->params,
                );
                if ($candParams === $refParamNames && $cand->static === $ref->isStatic()) {
                    $filtered[] = $cand;
                }
            }
            $candidates = $filtered;
        }

        if (count($candidates) === 0) {
            throw CompilationException::fromDiagnostic(new Diagnostic(
                code: 'RC_CLOSURE_NOT_FOUND',
                severity: Severity::Error,
                message: sprintf('Could not locate closure AST at %s:%d.', $file, $startLine),
                span: $span,
            ));
        }

        if (count($candidates) > 1) {
            throw CompilationException::fromDiagnostic(new Diagnostic(
                code: 'RC_CLOSURE_AMBIGUOUS_LINE',
                severity: Severity::Error,
                message: sprintf('Multiple indistinguishable closures found on %s:%d.', $file, $startLine),
                span: $span,
                hint: 'Place each route closure on its own line or use a controller class.',
            ));
        }

        $node = $candidates[0];

        // Validate AST body for unsupported constructs
        $this->validateAstNode($node, $file, $span);

        // Check captures
        $usedVars = $ref->getClosureUsedVariables();
        $scalarCaptures = [];

        if ($node instanceof Closure) {
            foreach ($node->uses as $use) {
                if ($use->byRef) {
                    throw CompilationException::fromDiagnostic(new Diagnostic(
                        code: 'RC_CLOSURE_BYREF_CAPTURE',
                        severity: Severity::Error,
                        message: sprintf('By-reference capture "&$%s" is not supported in compiled mode.', is_string($use->var->name) ? $use->var->name : 'var'),
                        span: $span,
                        hint: 'Capture by value or use a service/factory.',
                    ));
                }
            }
        }

        if ($usedVars !== []) {
            if (!$this->allowScalarCaptures) {
                throw CompilationException::fromDiagnostic(new Diagnostic(
                    code: 'RC_CLOSURE_CAPTURE_DISABLED',
                    severity: Severity::Error,
                    message: 'Closure variable captures are disabled by compiler options.',
                    span: $span,
                ));
            }

            foreach ($usedVars as $varName => $val) {
                if (!$this->isRecursivelyScalar($val)) {
                    throw CompilationException::fromDiagnostic(new Diagnostic(
                        code: 'RC_CLOSURE_UNSUPPORTED_CAPTURE',
                        severity: Severity::Error,
                        message: sprintf('Closure capture "$%s" of type "%s" is not a scalar/null/scalar-array.', $varName, get_debug_type($val)),
                        span: $span,
                        hint: 'Only scalar, null, and recursively scalar arrays can be captured in compiled mode.',
                    ));
                }
                $scalarCaptures[$varName] = $val;
            }
        }

        // Rewrite magic constants and ensure static closure
        $rewritten = $this->rewriteMagicConstants($node, $file);
        if ($rewritten instanceof Closure) {
            $rewritten->static = true;
        } elseif ($rewritten instanceof ArrowFunction) {
            $rewritten->static = true;
        }

        $printer = new Standard();
        $closureExprCode = $printer->prettyPrintExpr($rewritten);

        if ($scalarCaptures === []) {
            $factoryCode = $closureExprCode;
        } else {
            $assignments = '';
            foreach ($scalarCaptures as $k => $v) {
                $assignments .= '    $' . $k . ' = ' . var_export($v, true) . ";\n";
            }
            $factoryCode = "(static function (): \\Closure {\n" . $assignments . "    return " . $closureExprCode . ";\n})()";
        }

        return new ExtractedClosure(
            factoryCode: $factoryCode,
            scalarCaptures: $scalarCaptures,
            file: $file,
            line: $startLine,
        );
    }

    /**
     * Parse a PHP file and resolve all class/function/constant names to FQNs.
     *
     * @param string $file Path to PHP source file.
     *
     * @return list<Node\Stmt>
     *
     * @throws CompilationException When the source file cannot be read.
     */
    private function parseFileWithNames(string $file): array
    {
        if (isset($this->astCache[$file])) {
            return $this->astCache[$file];
        }

        $source = file_get_contents($file);
        if ($source === false) {
            throw CompilationException::fromDiagnostic(new Diagnostic(
                code: 'RC_CLOSURE_READ_ERROR',
                severity: Severity::Error,
                message: sprintf('Failed to read source file "%s".', $file),
                span: new SourceSpan(0, 0, $file),
            ));
        }

        $parser = new ParserFactory()->createForNewestSupportedVersion();
        $ast = $parser->parse($source) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => false, 'replaceNodes' => true]));
        /** @var list<Node\Stmt> $resolved */
        $resolved = $traverser->traverse($ast);

        $this->astCache[$file] = $resolved;

        return $resolved;
    }

    /**
     * Validate that a closure AST node contains no uncompilable constructs ($this, static locals, relative class scopes).
     *
     * @param Closure|ArrowFunction $node Parsed closure AST node.
     * @param string $file Source file path.
     * @param SourceSpan $span Source span for diagnostics.
     *
     * @return void
     *
     * @throws CompilationException When an unsupported construct is found.
     */
    private function validateAstNode(Closure|ArrowFunction $node, string $file, SourceSpan $span): void
    {
        $finder = new NodeFinder();

        // Reject $this usage
        $thisVar = $finder->findFirst($node, static fn (Node $n): bool => $n instanceof Variable && $n->name === 'this');
        if ($thisVar !== null) {
            throw CompilationException::fromDiagnostic(new Diagnostic(
                code: 'RC_CLOSURE_BOUND_THIS',
                severity: Severity::Error,
                message: 'Closure references $this, which is not supported in compiled mode.',
                span: new SourceSpan(0, 0, $file, $thisVar->getStartLine()),
            ));
        }

        // Reject static local variables
        $staticLocal = $finder->findFirst($node, static fn (Node $n): bool => $n instanceof Static_);
        if ($staticLocal !== null) {
            throw CompilationException::fromDiagnostic(new Diagnostic(
                code: 'RC_CLOSURE_STATIC_LOCAL',
                severity: Severity::Error,
                message: 'Closures with static local variables are not supported in compiled mode.',
                span: new SourceSpan(0, 0, $file, $staticLocal->getStartLine()),
            ));
        }

        // Reject self/static/parent class references
        $relativeClass = $finder->findFirst($node, static function (Node $n): bool {
            if (!$n instanceof Name) {
                return false;
            }
            $lower = strtolower($n->toString());

            return $lower === 'self' || $lower === 'static' || $lower === 'parent';
        });
        if ($relativeClass !== null) {
            throw CompilationException::fromDiagnostic(new Diagnostic(
                code: 'RC_CLOSURE_RELATIVE_CLASS',
                severity: Severity::Error,
                message: sprintf('Closure uses relative class scope "%s", which is not supported in compiled mode.', $relativeClass instanceof Name ? $relativeClass->toString() : ''),
                span: new SourceSpan(0, 0, $file, $relativeClass->getStartLine()),
            ));
        }

        // Reject unsupported magic constants (__CLASS__, __TRAIT__, __METHOD__, __FUNCTION__)
        $badMagic = $finder->findFirst($node, static function (Node $n): bool {
            return $n instanceof MagicConst\Class_
                || $n instanceof MagicConst\Trait_
                || $n instanceof MagicConst\Method
                || $n instanceof MagicConst\Function_;
        });
        if ($badMagic !== null) {
            throw CompilationException::fromDiagnostic(new Diagnostic(
                code: 'RC_CLOSURE_MAGIC_CONST',
                severity: Severity::Error,
                message: 'Closure uses scope-dependent magic constant (__CLASS__/__METHOD__/__FUNCTION__/__TRAIT__).',
                span: new SourceSpan(0, 0, $file, $badMagic->getStartLine()),
            ));
        }
    }

    private function rewriteMagicConstants(Closure|ArrowFunction $node, string $file): Closure|ArrowFunction
    {
        $dir = dirname($file);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($file, $dir) extends NodeVisitorAbstract {
            public function __construct(
                private readonly string $file,
                private readonly string $dir,
            ) {
            }

            public function leaveNode(Node $node): ?Node
            {
                if ($node instanceof MagicConst\File) {
                    return new String_($this->file);
                }
                if ($node instanceof MagicConst\Dir) {
                    return new String_($this->dir);
                }
                if ($node instanceof MagicConst\Line) {
                    return new Int_($node->getStartLine());
                }

                return null;
            }
        });

        /** @var list<Node> $res */
        $res = $traverser->traverse([$node]);
        /** @var Closure|ArrowFunction $out */
        $out = $res[0];

        return $out;
    }

    private function isRecursivelyScalar(mixed $val): bool
    {
        if ($val === null || is_scalar($val)) {
            return true;
        }
        if (is_array($val)) {
            foreach ($val as $v) {
                if (!$this->isRecursivelyScalar($v)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
