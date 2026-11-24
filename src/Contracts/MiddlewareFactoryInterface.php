<?php

declare(strict_types=1);

namespace Routir\Contracts;

use Psr\Http\Server\MiddlewareInterface;

interface MiddlewareFactoryInterface
{
    /**
     * @param list<string> $parameters
     */
    public function create(string $middlewareClass, array $parameters): MiddlewareInterface;
}
