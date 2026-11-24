<?php

declare(strict_types=1);

namespace Routir\Frontend\Lexer;

use Routir\Diagnostics\SourceSpan;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public int $offset,
        public int $length,
    ) {
    }

    public function span(?string $file = null, ?int $line = null): SourceSpan
    {
        return new SourceSpan($this->offset, $this->length, $file, $line);
    }
}
