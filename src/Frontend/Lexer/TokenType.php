<?php

declare(strict_types=1);

namespace Routir\Frontend\Lexer;

enum TokenType: string
{
    case Slash = 'Slash';
    case LeftBrace = 'LeftBrace';
    case RightBrace = 'RightBrace';
    case Colon = 'Colon';
    case Question = 'Question';
    case Star = 'Star';
    case Text = 'Text';
    case EOF = 'EOF';
}
