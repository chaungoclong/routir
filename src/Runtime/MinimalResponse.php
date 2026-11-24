<?php

declare(strict_types=1);

namespace Routir\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class MinimalResponse implements ResponseInterface
{
    /** @var array<string, list<string>> */
    private array $headers = [];
    /** @var array<string, string> */
    private array $headerNames = [];

    public function __construct(
        private int $statusCode = 200,
        private string $reasonPhrase = '',
        private ?StreamInterface $body = null,
        private string $protocolVersion = '1.1',
    ) {
        $this->body ??= new MinimalStream();
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): static
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;

        return $clone;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $lower = strtolower($name);
        if (!isset($this->headerNames[$lower])) {
            return [];
        }

        return $this->headers[$this->headerNames[$lower]];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        $clone = clone $this;
        $lower = strtolower($name);
        if (isset($clone->headerNames[$lower])) {
            unset($clone->headers[$clone->headerNames[$lower]]);
        }
        $clone->headerNames[$lower] = $name;
        $clone->headers[$name] = is_array($value) ? array_values($value) : [(string) $value];

        return $clone;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $clone = clone $this;
        $lower = strtolower($name);
        $values = is_array($value) ? array_values($value) : [(string) $value];
        if (isset($clone->headerNames[$lower])) {
            $orig = $clone->headerNames[$lower];
            $clone->headers[$orig] = array_merge($clone->headers[$orig], $values);
        } else {
            $clone->headerNames[$lower] = $name;
            $clone->headers[$name] = $values;
        }

        return $clone;
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        $lower = strtolower($name);
        if (isset($clone->headerNames[$lower])) {
            unset($clone->headers[$clone->headerNames[$lower]], $clone->headerNames[$lower]);
        }

        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body ?? new MinimalStream();
    }

    public function withBody(StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = $reasonPhrase;

        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }
}
