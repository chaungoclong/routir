<?php

declare(strict_types=1);

use Routir\Exception\ParseException;
use Routir\Frontend\Lexer\Lexer;
use Routir\Frontend\Lexer\TokenType;

test('tokenizes root pattern', function (): void {
    $tokens = new Lexer()->tokenize('/');

    expect($tokens)->toHaveCount(2);
    expect($tokens[0]->type)->toBe(TokenType::Slash)
        ->and($tokens[0]->offset)->toBe(0)
        ->and($tokens[0]->length)->toBe(1);
    expect($tokens[1]->type)->toBe(TokenType::EOF)
        ->and($tokens[1]->offset)->toBe(1)
        ->and($tokens[1]->length)->toBe(0);
});

test('tokenizes complex route pattern with spans', function (): void {
    $pattern = '/users/{id:int}/files-v1.0~a_b/{path:*}';
    $tokens = new Lexer()->tokenize($pattern);

    $types = array_map(static fn ($t) => $t->type, $tokens);
    expect($types)->toBe([
        TokenType::Slash,
        TokenType::Text,
        TokenType::Slash,
        TokenType::LeftBrace,
        TokenType::Text,
        TokenType::Colon,
        TokenType::Text,
        TokenType::RightBrace,
        TokenType::Slash,
        TokenType::Text,
        TokenType::Slash,
        TokenType::LeftBrace,
        TokenType::Text,
        TokenType::Colon,
        TokenType::Star,
        TokenType::RightBrace,
        TokenType::EOF,
    ]);

    expect($tokens[1]->lexeme)->toBe('users')
        ->and($tokens[1]->offset)->toBe(1)
        ->and($tokens[1]->length)->toBe(5);

    expect($tokens[9]->lexeme)->toBe('files-v1.0~a_b')
        ->and($tokens[9]->offset)->toBe(16)
        ->and($tokens[9]->length)->toBe(14);
});

test('rejects invalid character with diagnostic span', function (): void {
    try {
        new Lexer()->tokenize('/users/foo bar');
        expect(false)->toBeTrue('Expected ParseException');
    } catch (ParseException $e) {
        $errors = $e->diagnostics->errors();
        expect($errors)->toHaveCount(1);
        expect($errors[0]->code)->toBe('RC_LEX_INVALID_CHAR')
            ->and($errors[0]->span->offset)->toBe(10)
            ->and($errors[0]->span->length)->toBe(1)
            ->and($errors[0]->offendingText)->toBe(' ');
    }
});
