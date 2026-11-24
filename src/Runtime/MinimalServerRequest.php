<?php

declare(strict_types=1);

namespace Routir\Runtime;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class MinimalServerRequest implements ServerRequestInterface
{
    /** @var array<string, list<string>> */
    private array $headers = [];
    /** @var array<string, mixed> */
    private array $attributes = [];
    private StreamInterface $body;
    private UriInterface $uri;

    public function __construct(
        private string $method,
        private string $requestTarget,
    ) {
        $this->body = new MinimalStream();
        $qPos = strpos($requestTarget, '?');
        $path = $qPos === false ? $requestTarget : substr($requestTarget, 0, $qPos);
        $query = $qPos === false ? '' : substr($requestTarget, $qPos + 1);
        $this->uri = new MinimalUri($path, $query);
    }

    public static function fromGlobals(): self
    {
        $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $target = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $req = new self($method, $target);

        foreach ($_SERVER as $key => $val) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($val)) {
                $name = str_replace('_', '-', substr($key, 5));
                $req = $req->withHeader($name, $val);
            }
        }

        return $req;
    }

    public function getProtocolVersion(): string { return '1.1'; }
    public function withProtocolVersion(string $version): static { return clone $this; }
    public function getHeaders(): array { return $this->headers; }
    public function hasHeader(string $name): bool { return isset($this->headers[strtolower($name)]); }
    public function getHeader(string $name): array { return $this->headers[strtolower($name)] ?? []; }
    public function getHeaderLine(string $name): string { return implode(', ', $this->getHeader($name)); }

    /** @param string|string[] $value */
    public function withHeader(string $name, $value): static
    {
        $c = clone $this;
        $c->headers[strtolower($name)] = is_array($value) ? array_values($value) : [(string) $value];

        return $c;
    }

    /** @param string|string[] $value */
    public function withAddedHeader(string $name, $value): static
    {
        $c = clone $this;
        $c->headers[strtolower($name)] = array_values(array_merge($c->getHeader($name), is_array($value) ? $value : [(string) $value]));

        return $c;
    }

    public function withoutHeader(string $name): static { $c = clone $this; unset($c->headers[strtolower($name)]); return $c; }
    public function getBody(): StreamInterface { return $this->body; }
    public function withBody(StreamInterface $body): static { $c = clone $this; $c->body = $body; return $c; }
    public function getRequestTarget(): string { return $this->requestTarget; }
    public function withRequestTarget(string $requestTarget): static { $c = clone $this; $c->requestTarget = $requestTarget; return $c; }
    public function getMethod(): string { return $this->method; }
    public function withMethod(string $method): static { $c = clone $this; $c->method = $method; return $c; }
    public function getUri(): UriInterface { return $this->uri; }
    public function withUri(UriInterface $uri, bool $preserveHost = false): static { $c = clone $this; $c->uri = $uri; return $c; }

    /** @return array<string, mixed> */
    public function getServerParams(): array { return []; }
    /** @return array<string, mixed> */
    public function getCookieParams(): array { return []; }
    /** @param array<string, mixed> $cookies */
    public function withCookieParams(array $cookies): static { return clone $this; }
    /** @return array<string, mixed> */
    public function getQueryParams(): array { return []; }
    /** @param array<string, mixed> $query */
    public function withQueryParams(array $query): static { return clone $this; }
    /** @return array<string, mixed> */
    public function getUploadedFiles(): array { return []; }
    /** @param array<string, mixed> $uploadedFiles */
    public function withUploadedFiles(array $uploadedFiles): static { return clone $this; }
    /** @return array<string, mixed>|object|null */
    public function getParsedBody() { return null; }
    /** @param array<string, mixed>|object|null $data */
    public function withParsedBody($data): static { return clone $this; }
    /** @return array<string, mixed> */
    public function getAttributes(): array { return $this->attributes; }
    public function getAttribute(string $name, $default = null) { return $this->attributes[$name] ?? $default; }
    public function withAttribute(string $name, $value): static { $c = clone $this; $c->attributes[$name] = $value; return $c; }
    public function withoutAttribute(string $name): static { $c = clone $this; unset($c->attributes[$name]); return $c; }
}
