<?php

declare(strict_types=1);

namespace G\Container;

use Psr\Container\ContainerInterface;
use G\Container\Exception\NotFoundException;
use G\Container\Resolver\{Resolver, ResolverInterface};
use ReflectionClass;

/**
 * PSR-11 container.
 *
 * An entry is either a plain value, returned as is, or something the resolver can build
 * (a factory or a class name), built on first access and then shared, unless the entry
 * was flagged with isFactory().
 *
 * A class which was never defined is built on first access when it is instantiable
 * (autowiring) and then shared. The container itself is available under
 * Psr\Container\ContainerInterface and its own class names, unless they were defined.
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

        if (!array_key_exists($id, $this->entries)) {
            if ($this->isSelf($id)) {
                return $this;
            }

            if ($this->isAutowirable($id)) {
                return $this->resolved[$id] = $this->resolver->resolve($id);
            }

            if (interface_exists($id) || trait_exists($id) || enum_exists($id) || class_exists($id)) {
                throw new NotFoundException(
                    "No entry found for '$id': it cannot be instantiated, define the implementation"
                        . " to use with set('$id', ...)"
                );
            }

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
        return $this->isDefined($id) || $this->isAutowirable($id);
    }

    /**
     * Tell whether an identifier was defined with set() or designates the container itself,
     * as opposed to a class that would only be autowired.
     *
     * @param string $id The identifier of the entry.
     *
     * @return bool
     */
    public function isDefined(string $id): bool
    {
        return array_key_exists($id, $this->entries) || $this->isSelf($id);
    }

    /**
     * Tell whether the shared instance of an entry has already been built by get().
     *
     * Always false for a factory entry (never shared) and for the container itself.
     * Useful to act on a service only when it was used, without building it.
     *
     * @param string $id The identifier of the entry.
     *
     * @return bool
     */
    public function isResolved(string $id): bool
    {
        return array_key_exists($id, $this->resolved);
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

    /**
     * Tell whether an identifier designates the container itself.
     */
    protected function isSelf(string $id): bool
    {
        return $id === ContainerInterface::class || $id === self::class || $id === static::class;
    }

    /**
     * Tell whether an undefined identifier is the name of an instantiable class.
     */
    protected function isAutowirable(string $id): bool
    {
        return class_exists($id) && (new ReflectionClass($id))->isInstantiable();
    }
}
