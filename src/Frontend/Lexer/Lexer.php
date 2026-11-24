<?php

declare(strict_types=1);

namespace Routir\Frontend\Lexer;

use Routir\Diagnostics\Diagnostic;
use Routir\Diagnostics\Severity;
use Routir\Diagnostics\SourceSpan;
use Routir\Exception\ParseException;

final class Lexer
{
    /**
     * Tokenize a route pattern string into a stream of lexical tokens.
     *
     * @param string $input Raw route pattern string.
     * @param ?string $file Optional source file path for diagnostics.
     * @param ?int $line Optional source line number for diagnostics.
     *
     * @return list<Token>
     *
     * @throws ParseException When an invalid character is encountered in the pattern.
     */
    public function tokenize(string $input, ?string $file = null, ?int $line = null): array
    {
        $tokens = [];
        $len = strlen($input);
        $i = 0;

        while ($i < $len) {
            $ch = $input[$i];

            $singleType = match ($ch) {
                '/' => TokenType::Slash,
                '{' => TokenType::LeftBrace,
                '}' => TokenType::RightBrace,
                ':' => TokenType::Colon,
                '?' => TokenType::Question,
                '*' => TokenType::Star,
                default => null,
            };

            if ($singleType !== null) {
                $tokens[] = new Token($singleType, $ch, $i, 1);
                $i++;
                continue;
            }

            if ($this->isLiteralChar($ch)) {
                $start = $i;
                $i++;
                while ($i < $len && $this->isLiteralChar($input[$i])) {
                    $i++;
                }
                $lexeme = substr($input, $start, $i - $start);
                $tokens[] = new Token(TokenType::Text, $lexeme, $start, $i - $start);
                continue;
            }

            throw ParseException::fromDiagnostic(new Diagnostic(
                code: 'RC_LEX_INVALID_CHAR',
                severity: Severity::Error,
                message: sprintf('Unexpected character "%s" in route pattern.', $this->formatChar($ch)),
                span: new SourceSpan($i, 1, $file, $line),
                offendingText: $ch,
                hint: 'Allowed literal characters are ASCII letters, digits, "-", "_", ".", "~", or structural delimiters "/", "{", "}", ":", "?", "*".',
            ));
        }

        $tokens[] = new Token(TokenType::EOF, '', $len, 0);

        return $tokens;
    }

    private function isLiteralChar(string $ch): bool
    {
        $ord = ord($ch);

        return ($ord >= 97 && $ord <= 122) // a-z
            || ($ord >= 65 && $ord <= 90) // A-Z
            || ($ord >= 48 && $ord <= 57) // 0-9
            || $ch === '-'
            || $ch === '_'
            || $ch === '.'
            || $ch === '~';
    }

    private function formatChar(string $ch): string
    {
        $ord = ord($ch);
        if ($ord < 32 || $ord === 127) {
            return sprintf('\\x%02X', $ord);
        }

        return $ch;
    }
}
