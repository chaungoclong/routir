<?php

declare(strict_types=1);

namespace Routir\Frontend\Handler;

final readonly class ExtractedClosure
{
    /**
     * @param array<string, mixed> $scalarCaptures
     */
    public function __construct(
        public string $factoryCode,
        public array $scalarCaptures,
        public string $file,
        public int $line,
    ) {
    }
}
