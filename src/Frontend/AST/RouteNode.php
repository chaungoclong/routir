<?php

declare(strict_types=1);

namespace Routir\Frontend\AST;

use Routir\Diagnostics\SourceSpan;

final readonly class RouteNode implements Node
{
    /**
     * @param list<SegmentNode> $segments
     */
    public function __construct(
        public string $rawPattern,
        public array $segments,
        public SourceSpan $span,
    ) {
    }

    public function span(): SourceSpan
    {
        return $this->span;
    }
}
