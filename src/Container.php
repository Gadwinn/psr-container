<?php

declare(strict_types=1);

namespace G\Container;

use Psr\Container\ContainerInterface;
use G\Container\Exception\NotFoundException;
use G\Container\Resolver\{Resolver, ResolverInterface};

/**
 * PSR-11 container.
 *
 * An entry is either a plain value, returned as is, or something the resolver can build
 * (a factory or a class name), built on first access and then shared, unless the entry
 * was flagged with isFactory().
 */
class Container implements ContainerInterface
{
    /** @var array<string,mixed> */
    protected array $entries = [];

    /** The last id that was set */
    protected string $lastId = '';

    /** @var array<string,bool> */
    protected array $factories = [];

    /** @var array<string,mixed> */
    protected array $resolved = [];

    protected ResolverInterface $resolver;

    public function __construct(?ResolverInterface $resolver = null)
    {
        $this->resolver = $resolver ?? new Resolver($this);
    }

    public function get(string $id)
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (!$this->has($id)) {
            throw new NotFoundException("No entry found for '$id'");
        }

        if ($this->resolver->isResolvable($this->entries[$id])) {
            $resolved = $this->resolver->resolve($this->entries[$id]);

            if (isset($this->factories[$id])) {
                return $resolved;
            }

            return $this->resolved[$id] = $resolved;
        }

        return $this->resolved[$id] = $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->entries[$id]);
    }

    /**
     * Define an entry, replacing any previous definition of the same identifier.
     *
     * @param string $id The identifier of the entry.
     * @param mixed $value The value of the entry; null removes the entry.
     *
     * @return self
     */
    public function set(string $id, $value): self
    {
        // Forget everything known about a previous definition (shared instance, factory flag)
        unset($this->entries[$id], $this->factories[$id], $this->resolved[$id]);

        if ($value === null) {
            if ($this->lastId === $id) {
                $this->lastId = '';
            }

            return $this;
        }

        $this->lastId = $id;
        $this->entries[$id] = $value;
        return $this;
    }

    /**
     * Flag an entry as a factory: it is built again on every get() instead of being shared.
     *
     * @param string $id The identifier of the entry.
     *
     * If empty, the last identifier set will be used.
     *
     * @return self
     */
    public function isFactory(string $id = ''): self
    {
        $id = $id !== '' ? $id : $this->lastId;
        if ($id !== '') {
            $this->factories[$id] = true;
            unset($this->resolved[$id]);
        }
        return $this;
    }
}
