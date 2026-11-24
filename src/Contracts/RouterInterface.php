<?php

declare(strict_types=1);

namespace Routir\Contracts;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface RouterInterface
{
    public function matchRequest(string $method, string $requestTarget): MatchResult;

    public function dispatch(ServerRequestInterface $request): ResponseInterface;

    /**
     * @param array<string, mixed> $params
     */
    public function route(string $name, array $params = []): string;

    public function has(string $name): bool;
}
