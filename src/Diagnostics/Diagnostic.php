<?php

declare(strict_types=1);

namespace Routir\Diagnostics;

final readonly class Diagnostic
{
    public function __construct(
        public string $code,
        public Severity $severity,
        public string $message,
        public SourceSpan $span,
        public string $offendingText = '',
        public string $hint = '',
    ) {
    }

    public function format(): string
    {
        $loc = '';
        if ($this->span->file !== null) {
            $loc .= $this->span->file;
            if ($this->span->line !== null) {
                $loc .= ':' . $this->span->line;
            }
            $loc .= ' ';
        }
        $loc .= sprintf('[offset %d, len %d]', $this->span->offset, $this->span->length);

        $out = sprintf('[%s] %s: %s (%s)', $this->code, strtoupper($this->severity->value), $this->message, $loc);
        if ($this->offendingText !== '') {
            $out .= sprintf(" near '%s'", $this->offendingText);
        }
        if ($this->hint !== '') {
            $out .= sprintf(' — Hint: %s', $this->hint);
        }

        return $out;
    }
}
