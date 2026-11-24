<?php

declare(strict_types=1);

namespace Routir\IR\LIR;

use Routir\IR\HIR\HIRModule;

final class LIRModule
{
    /**
     * @param array<string, LIRFunction> $functions
     * @param array<string, array<string, array{id: int, defaults: array<string, string>}>> $staticTables
     */
    public function __construct(
        public readonly HIRModule $hir,
        public array $functions = [],
        public array $staticTables = [],
    ) {
    }

    public function dump(): string
    {
        $out = '';
        foreach ($this->staticTables as $method => $table) {
            $out .= sprintf("static_table %s (%d entries)\n", $method, count($table));
        }
        foreach ($this->functions as $fn) {
            $out .= $fn->dump();
        }

        return $out;
    }
}
