<?php

declare(strict_types=1);

namespace Routir\Runtime;

use Psr\Http\Message\UriInterface;

final class MinimalUri implements UriInterface
{
    public function __construct(
        private string $path = '/',
        private string $query = '',
    ) {
    }

    public function getScheme(): string { return ''; }
    public function getAuthority(): string { return ''; }
    public function getUserInfo(): string { return ''; }
    public function getHost(): string { return ''; }
    public function getPort(): ?int { return null; }
    public function getPath(): string { return $this->path; }
    public function getQuery(): string { return $this->query; }
    public function getFragment(): string { return ''; }
    public function withScheme(string $scheme): static { return clone $this; }
    public function withUserInfo(string $user, ?string $password = null): static { return clone $this; }
    public function withHost(string $host): static { return clone $this; }
    public function withPort(?int $port): static { return clone $this; }
    public function withPath(string $path): static { $c = clone $this; $c->path = $path; return $c; }
    public function withQuery(string $query): static { $c = clone $this; $c->query = $query; return $c; }
    public function withFragment(string $fragment): static { return clone $this; }
    public function __toString(): string { return $this->query !== '' ? $this->path . '?' . $this->query : $this->path; }
}
