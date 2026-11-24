<?php

declare(strict_types=1);

namespace Routir\Contracts;

interface MatcherInterface
{
    public function match(string $method, string $requestTarget): MatchResult;
}
