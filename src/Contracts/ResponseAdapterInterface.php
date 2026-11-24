<?php

declare(strict_types=1);

namespace Routir\Contracts;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface ResponseAdapterInterface
{
    public function adapt(mixed $result, ServerRequestInterface $request): ResponseInterface;
}
