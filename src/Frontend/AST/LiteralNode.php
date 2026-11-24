<?php

declare(strict_types=1);

namespace Routir\Frontend\AST;

use Routir\Diagnostics\SourceSpan;

final readonly class LiteralNode implements SegmentNode
{
    public function __construct(
        public string $value,
        public SourceSpan $span,
    ) {
    }

    public function span(): SourceSpan
    {
        return $this->span;
    }
}
