<?php

declare(strict_types=1);

namespace Routir\Exception;

use Routir\Diagnostics\Diagnostic;
use Routir\Diagnostics\DiagnosticBag;
use Throwable;

class DiagnosticException extends RoutirException
{
    final public function __construct(
        public readonly DiagnosticBag $diagnostics,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        if ($message === '') {
            $message = $diagnostics->formatAll();
        }
        parent::__construct($message, $code, $previous);
    }

    public static function fromDiagnostic(Diagnostic $diagnostic): self
    {
        $bag = new DiagnosticBag();
        $bag->add($diagnostic);

        return new static($bag, $diagnostic->format());
    }
}
