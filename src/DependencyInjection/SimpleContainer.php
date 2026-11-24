<?php

declare(strict_types=1);

namespace Routir\DependencyInjection;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Routir\Exception\RoutirException;

final class SimpleContainer implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];

    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(array $entries = [])
    {
        $this->entries = $entries;
    }

    public function set(string $id, mixed $value): void
    {
        $this->entries[$id] = $value;
    }

    /**
     * Retrieve an entry from the container by its identifier.
     *
     * @param string $id Service identifier.
     *
     * @return mixed
     *
     * @throws NotFoundExceptionInterface When no entry is registered for the given identifier.
     */
    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->entries)) {
            throw new class (sprintf('Service "%s" not found in container.', $id)) extends RoutirException implements NotFoundExceptionInterface {
            };
        }

        $entry = $this->entries[$id];

        return $entry instanceof Closure ? $entry($this) : $entry;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
