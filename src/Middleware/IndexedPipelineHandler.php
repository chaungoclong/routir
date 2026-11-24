<?php

declare(strict_types=1);

namespace Routir\Middleware;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class IndexedPipelineHandler implements RequestHandlerInterface
{
    /**
     * @param list<string> $entries
     * @param Closure(string): MiddlewareInterface $resolver
     */
    public function __construct(
        private array $entries,
        private int $index,
        private RequestHandlerInterface $terminal,
        private Closure $resolver,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->entries[$this->index])) {
            return $this->terminal->handle($request);
        }

        $middleware = ($this->resolver)($this->entries[$this->index]);
        $next = new self($this->entries, $this->index + 1, $this->terminal, $this->resolver);

        return $middleware->process($request, $next);
    }
}
