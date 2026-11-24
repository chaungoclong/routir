<?php

declare(strict_types=1);

namespace Routir\Diagnostics;

final class DiagnosticBag
{
    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function add(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /**
     * Check whether any recorded diagnostic has error severity.
     *
     * @return bool True if at least one error diagnostic exists.
     */
    public function hasErrors(): bool
    {
        return array_any(
            $this->diagnostics,
            static fn (Diagnostic $d): bool => $d->severity === Severity::Error,
        );
    }

    /** @return list<Diagnostic> */
    public function all(): array
    {
        return $this->diagnostics;
    }

    /** @return list<Diagnostic> */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            static fn (Diagnostic $d): bool => $d->severity === Severity::Error,
        ));
    }

    /** @return list<Diagnostic> */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            static fn (Diagnostic $d): bool => $d->severity === Severity::Warning,
        ));
    }

    public function merge(self $other): void
    {
        foreach ($other->diagnostics as $diagnostic) {
            $this->diagnostics[] = $diagnostic;
        }
    }

    public function formatAll(): string
    {
        return implode("\n", array_map(
            static fn (Diagnostic $d): string => $d->format(),
            $this->diagnostics,
        ));
    }
}
