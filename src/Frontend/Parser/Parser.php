<?php

declare(strict_types=1);

namespace Routir\Frontend\Parser;

use Routir\Diagnostics\Diagnostic;
use Routir\Diagnostics\Severity;
use Routir\Diagnostics\SourceSpan;
use Routir\Exception\ParseException;
use Routir\Frontend\AST\LiteralNode;
use Routir\Frontend\AST\ParameterNode;
use Routir\Frontend\AST\RouteNode;
use Routir\Frontend\AST\SegmentNode;
use Routir\Frontend\Lexer\Lexer;
use Routir\Frontend\Lexer\Token;
use Routir\Frontend\Lexer\TokenType;

final class Parser
{
    /** @var list<Token> */
    private array $tokens = [];
    private int $pos = 0;
    private ?string $file = null;
    private ?int $line = null;

    public function __construct(
        private readonly Lexer $lexer = new Lexer(),
    ) {
    }

    /**
     * Parse a route pattern into an immutable RouteNode AST.
     *
     * @param string $pattern Route pattern string starting with "/".
     * @param ?string $file Optional source file path for diagnostics.
     * @param ?int $line Optional source line number for diagnostics.
     *
     * @return RouteNode
     *
     * @throws ParseException When lexical or syntactic validation fails.
     */
    public function parse(string $pattern, ?string $file = null, ?int $line = null): RouteNode
    {
        $this->tokens = $this->lexer->tokenize($pattern, $file, $line);
        $this->pos = 0;
        $this->file = $file;
        $this->line = $line;

        $first = $this->peek();
        if ($first->type !== TokenType::Slash) {
            $this->error(
                code: 'RC_PARSE_LEADING_SLASH',
                message: 'Route pattern must start with "/".',
                token: $first,
                hint: 'Prepend "/" to the route pattern, e.g. "/users".',
            );
        }

        $startOffset = $first->offset;
        $this->advance(); // consume leading '/'

        /** @var list<SegmentNode> $segments */
        $segments = [];

        if ($this->tokens[$this->pos]->type !== TokenType::EOF) {
            $segments[] = $this->parseSegment();

            while ($this->tokens[$this->pos]->type === TokenType::Slash) {
                $slash = $this->advance();
                if ($this->tokens[$this->pos]->type === TokenType::EOF) {
                    $this->error(
                        code: 'RC_PARSE_EMPTY_SEGMENT',
                        message: 'Trailing "/" creates an empty segment in non-root route pattern.',
                        token: $slash,
                        hint: 'Remove the trailing "/" or add a segment after it.',
                    );
                }
                $segments[] = $this->parseSegment();
            }
        }

        $eof = $this->peek();
        if ($eof->type !== TokenType::EOF) {
            $this->error(
                code: 'RC_PARSE_EXPECTED_EOF',
                message: sprintf('Unexpected token "%s" (%s); expected "/" or end of pattern.', $eof->lexeme, $eof->type->value),
                token: $eof,
                hint: 'Segments must be separated by "/".',
            );
        }

        $totalLength = $eof->offset - $startOffset;

        return new RouteNode(
            rawPattern: $pattern,
            segments: $segments,
            span: new SourceSpan($startOffset, $totalLength, $this->file, $this->line),
        );
    }

    /**
     * Parse a single path segment (literal or parameter).
     *
     * @return SegmentNode
     *
     * @throws ParseException When the segment is empty or malformed.
     *
     * @phpstan-impure
     */
    private function parseSegment(): SegmentNode
    {
        $tok = $this->peek();

        if ($tok->type === TokenType::Text) {
            $this->advance();

            return new LiteralNode(
                value: $tok->lexeme,
                span: $tok->span($this->file, $this->line),
            );
        }

        if ($tok->type === TokenType::LeftBrace) {
            return $this->parseParameter();
        }

        if ($tok->type === TokenType::Slash) {
            $this->error(
                code: 'RC_PARSE_EMPTY_SEGMENT',
                message: 'Empty segment ("//") is not allowed in route pattern.',
                token: $tok,
                hint: 'Remove the duplicate "/".',
            );
        }

        $this->error(
            code: 'RC_PARSE_EXPECTED_SEGMENT',
            message: sprintf('Unexpected token "%s" (%s) where a segment (literal or "{param}") was expected.', $tok->lexeme, $tok->type->value),
            token: $tok,
            hint: 'A segment must be a literal path part or a "{parameter}" expression.',
        );
    }

    /**
     * Parse a parameter segment "{name[:constraint][?]}".
     *
     * @return ParameterNode
     *
     * @throws ParseException When the parameter syntax, identifier, or constraint is invalid.
     */
    private function parseParameter(): ParameterNode
    {
        $leftBrace = $this->advance(); // consume '{'

        $nameTok = $this->peek();
        if ($nameTok->type !== TokenType::Text) {
            $this->error(
                code: 'RC_PARSE_EXPECTED_IDENT',
                message: sprintf('Expected parameter identifier after "{", got "%s" (%s).', $nameTok->lexeme, $nameTok->type->value),
                token: $nameTok,
                hint: 'Parameter names must start with a letter or "_" followed by letters, digits, or "_".',
            );
        }
        $this->advance();

        if (!$this->isValidIdentifier($nameTok->lexeme)) {
            $this->error(
                code: 'RC_PARSE_INVALID_IDENT',
                message: sprintf('Invalid parameter identifier "%s".', $nameTok->lexeme),
                token: $nameTok,
                hint: 'Identifier must match (letter | "_") { letter | digit | "_" }.',
            );
        }

        $constraint = null;
        $wildcard = false;

        if ($this->peek()->type === TokenType::Colon) {
            $this->advance(); // consume ':'
            $constraintTok = $this->peek();

            if ($constraintTok->type === TokenType::Star) {
                $this->advance();
                $constraint = '*';
                $wildcard = true;
            } elseif ($constraintTok->type === TokenType::Text) {
                $this->advance();
                if (!$this->isValidIdentifier($constraintTok->lexeme)) {
                    $this->error(
                        code: 'RC_PARSE_INVALID_CONSTRAINT',
                        message: sprintf('Invalid constraint identifier "%s".', $constraintTok->lexeme),
                        token: $constraintTok,
                        hint: 'Constraint must be a valid identifier or "*".',
                    );
                }
                $constraint = $constraintTok->lexeme;
            } else {
                $this->error(
                    code: 'RC_PARSE_EXPECTED_CONSTRAINT',
                    message: sprintf('Expected constraint identifier or "*" after ":", got "%s" (%s).', $constraintTok->lexeme, $constraintTok->type->value),
                    token: $constraintTok,
                    hint: 'Use a constraint name like ":int" or wildcard ":*".',
                );
            }
        }

        $optional = false;
        if ($this->peek()->type === TokenType::Question) {
            $questionTok = $this->advance();
            $optional = true;
            if ($wildcard) {
                $this->error(
                    code: 'RC_PARSE_OPTIONAL_WILDCARD',
                    message: 'Wildcard parameter cannot be optional.',
                    token: $questionTok,
                    hint: 'Remove "?" from the wildcard parameter.',
                );
            }
        }

        $rightBrace = $this->peek();
        if ($rightBrace->type !== TokenType::RightBrace) {
            $this->error(
                code: 'RC_PARSE_EXPECTED_RBRACE',
                message: sprintf('Expected "}" to close parameter "%s", got "%s" (%s).', $nameTok->lexeme, $rightBrace->lexeme, $rightBrace->type->value),
                token: $rightBrace,
                hint: 'Close the parameter with "}".',
            );
        }
        $this->advance();

        $span = new SourceSpan(
            offset: $leftBrace->offset,
            length: ($rightBrace->offset + $rightBrace->length) - $leftBrace->offset,
            file: $this->file,
            line: $this->line,
        );

        return new ParameterNode(
            name: $nameTok->lexeme,
            constraint: $constraint,
            optional: $optional,
            wildcard: $wildcard,
            span: $span,
        );
    }

    private function isValidIdentifier(string $lexeme): bool
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $lexeme) === 1;
    }

    private function peek(): Token
    {
        return $this->tokens[$this->pos];
    }

    /** @phpstan-impure */
    private function advance(): Token
    {
        $tok = $this->tokens[$this->pos];
        if ($tok->type !== TokenType::EOF) {
            $this->pos++;
        }

        return $tok;
    }

    /**
     * Throw a ParseException with a formatted diagnostic at the given token span.
     *
     * @param string $code Stable diagnostic code.
     * @param string $message Error description.
     * @param Token $token Offending token.
     * @param string $hint Actionable fix suggestion.
     *
     * @return never
     *
     * @throws ParseException Always thrown.
     */
    private function error(string $code, string $message, Token $token, string $hint): never
    {
        throw ParseException::fromDiagnostic(new Diagnostic(
            code: $code,
            severity: Severity::Error,
            message: $message,
            span: $token->span($this->file, $this->line),
            offendingText: $token->lexeme,
            hint: $hint,
        ));
    }
}
