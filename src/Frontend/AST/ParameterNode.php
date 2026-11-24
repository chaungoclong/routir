<?php

declare(strict_types=1);

namespace Routir\Frontend\AST;

use Routir\Diagnostics\SourceSpan;

final readonly class ParameterNode implements SegmentNode
{
    public function __construct(
        public string $name,
        public ?string $constraint,
        public bool $optional,
        public bool $wildcard,
        public SourceSpan $span,
    ) {
    }

    public function span(): SourceSpan
    {
        return $this->span;
    }
}
