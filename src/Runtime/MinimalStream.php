<?php

declare(strict_types=1);

namespace Routir\Runtime;

use Psr\Http\Message\StreamInterface;

final class MinimalStream implements StreamInterface
{
    private string $content;
    private int $pos = 0;

    public function __construct(string $content = '')
    {
        $this->content = $content;
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return $this->pos;
    }

    public function eof(): bool
    {
        return $this->pos >= strlen($this->content);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $len = strlen($this->content);
        $this->pos = match ($whence) {
            SEEK_SET => max(0, min($len, $offset)),
            SEEK_CUR => max(0, min($len, $this->pos + $offset)),
            SEEK_END => max(0, min($len, $len + $offset)),
            default => $this->pos,
        };
    }

    public function rewind(): void
    {
        $this->pos = 0;
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function write(string $string): int
    {
        $this->content = substr($this->content, 0, $this->pos) . $string;
        $this->pos += strlen($string);

        return strlen($string);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $chunk = substr($this->content, $this->pos, $length);
        $this->pos += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $rest = substr($this->content, $this->pos);
        $this->pos = strlen($this->content);

        return $rest;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
