<?php

declare(strict_types=1);

namespace Routir\Frontend\AST;

use Routir\Diagnostics\SourceSpan;

interface Node
{
    public function span(): SourceSpan;
}
