<?php

declare(strict_types=1);

namespace Routir\Diagnostics;

final readonly class SourceSpan
{
    public function __construct(
        public int $offset,
        public int $length,
        public ?string $file = null,
        public ?int $line = null,
    ) {
    }

    public function withLocation(?string $file, ?int $line): self
    {
        return new self($this->offset, $this->length, $file, $line);
    }

    public function endOffset(): int
    {
        return $this->offset + $this->length;
    }
}
