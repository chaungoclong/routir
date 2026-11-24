<?php

declare(strict_types=1);

use Routir\Exception\ParseException;
use Routir\Frontend\AST\LiteralNode;
use Routir\Frontend\AST\ParameterNode;
use Routir\Frontend\Parser\Parser;

test('parses root', function (): void {
    $node = new Parser()->parse('/');

    expect($node->rawPattern)->toBe('/')
        ->and($node->segments)->toBe([])
        ->and($node->span->offset)->toBe(0)
        ->and($node->span->length)->toBe(1);
});

test('parses literal parameter optional and wildcard', function (): void {
    $node = new Parser()->parse('/users/{id:int}/{tab?}/{rest:*}');

    expect($node->segments)->toHaveCount(4);

    expect($node->segments[0])->toBeInstanceOf(LiteralNode::class);
    /** @var LiteralNode $seg0 */
    $seg0 = $node->segments[0];
    expect($seg0->value)->toBe('users');

    expect($node->segments[1])->toBeInstanceOf(ParameterNode::class);
    /** @var ParameterNode $seg1 */
    $seg1 = $node->segments[1];
    expect($seg1->name)->toBe('id')
        ->and($seg1->constraint)->toBe('int')
        ->and($seg1->optional)->toBeFalse()
        ->and($seg1->wildcard)->toBeFalse();

    expect($node->segments[2])->toBeInstanceOf(ParameterNode::class);
    /** @var ParameterNode $seg2 */
    $seg2 = $node->segments[2];
    expect($seg2->name)->toBe('tab')
        ->and($seg2->constraint)->toBeNull()
        ->and($seg2->optional)->toBeTrue()
        ->and($seg2->wildcard)->toBeFalse();

    expect($node->segments[3])->toBeInstanceOf(ParameterNode::class);
    /** @var ParameterNode $seg3 */
    $seg3 = $node->segments[3];
    expect($seg3->name)->toBe('rest')
        ->and($seg3->constraint)->toBe('*')
        ->and($seg3->optional)->toBeFalse()
        ->and($seg3->wildcard)->toBeTrue();
});

test('rejects invalid patterns', function (string $pattern, string $expectedCode): void {
    try {
        new Parser()->parse($pattern);
        expect(false)->toBeTrue("Expected ParseException for pattern: $pattern");
    } catch (ParseException $e) {
        $errors = $e->diagnostics->errors();
        expect($errors)->not->toBeEmpty();
        expect($errors[0]->code)->toBe($expectedCode);
    }
/** @phpstan-ignore method.notFound */
})->with([
    'empty string' => ['', 'RC_PARSE_LEADING_SLASH'],
    'no leading slash' => ['users', 'RC_PARSE_LEADING_SLASH'],
    'double slash' => ['/users//profile', 'RC_PARSE_EMPTY_SEGMENT'],
    'trailing slash' => ['/users/', 'RC_PARSE_EMPTY_SEGMENT'],
    'unclosed brace' => ['/users/{id', 'RC_PARSE_EXPECTED_RBRACE'],
    'empty brace' => ['/users/{}', 'RC_PARSE_EXPECTED_IDENT'],
    'invalid param ident starting with digit' => ['/users/{123}', 'RC_PARSE_INVALID_IDENT'],
    'invalid param ident with hyphen' => ['/users/{user-id}', 'RC_PARSE_INVALID_IDENT'],
    'missing constraint after colon' => ['/users/{id:}', 'RC_PARSE_EXPECTED_CONSTRAINT'],
    'invalid constraint ident' => ['/users/{id:123}', 'RC_PARSE_INVALID_CONSTRAINT'],
    'optional wildcard' => ['/files/{path:*?}', 'RC_PARSE_OPTIONAL_WILDCARD'],
    'stray right brace' => ['/users/id}', 'RC_PARSE_EXPECTED_EOF'],
    'adjacent literal and brace' => ['/users/pre{id}', 'RC_PARSE_EXPECTED_EOF'],
]);
