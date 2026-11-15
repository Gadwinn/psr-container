<?php

declare(strict_types=1);

namespace G\Container\Resolvers;

use Closure;
use G\Container\Exceptions\{ContainerException, NotFoundException};
use Psr\Container\{ContainerInterface, NotFoundExceptionInterface};
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Turns a container entry into a value:
 *  - a factory (a Closure or an array callable such as [$object, 'method']) is called;
 *  - a class name is instantiated, its constructor dependencies being read from the container.
 *
 * Anything else is a plain value and is not resolvable. In particular, a string is only
 * resolvable when it is a class name (never because it is a function name such as "date"),
 * and an object other than a Closure is always a value, even when it is invokable.
 */
class Resolver implements ResolverInterface
{
    /** @var array<string,true> Entries being resolved, to detect circular dependencies */
    private array $resolving = [];

    public function __construct(
        private ContainerInterface $container
    ) {
    }

    public function resolve($entry, array $parameters = []): mixed
    {
        if (!$this->isResolvable($entry)) {
            throw new NotFoundException("Entry '" . $this->describe($entry) . "' is not resolvable");
        }

        $key = $this->describe($entry, true);
        if (isset($this->resolving[$key])) {
            throw new ContainerException(
                "Circular dependency detected while resolving '" . $this->describe($entry) . "'"
            );
        }

        $this->resolving[$key] = true;
        try {
            if (is_string($entry)) {
                /** @var class-string $entry */
                return $this->resolveClass($entry, $parameters);
            }

            /** @var callable $entry */
            return $this->resolveCallable($entry, $parameters);
        } finally {
            // Always released, otherwise a second resolution (factories) or a resolution
            // following a failure would be reported as a circular dependency.
            unset($this->resolving[$key]);
        }
    }

    public function isResolvable($entry): bool
    {
        if ($entry instanceof Closure) {
            return true;
        }

        if (is_string($entry)) {
            return class_exists($entry);
        }

        return is_array($entry) && is_callable($entry);
    }

    /* --- */

    /**
     * @param callable $callable
     * @param array<string|int,mixed> $parameters
     *
     * @return mixed
     */
    protected function resolveCallable(callable $callable, array $parameters = []): mixed
    {
        return call_user_func_array($callable, $parameters);
    }

    /**
     * @param class-string $id
     * @param array<string|int,mixed> $parameters Constructor arguments, by parameter name.
     *
     * @return object
     * @throws ContainerException
     */
    protected function resolveClass(string $id, array $parameters): object
    {
        $reflection = new ReflectionClass($id);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Class '$id' is not instantiable");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        return $reflection->newInstanceArgs($this->resolveParameters($constructor, $parameters));
    }

    /**
     * @param ReflectionMethod $constructor
     * @param array<string|int,mixed> $parameters Constructor arguments, by parameter name.
     *
     * @return list<mixed>
     * @throws ContainerException
     */
    protected function resolveParameters(ReflectionMethod $constructor, array $parameters): array
    {
        $dependencies = [];
        foreach ($constructor->getParameters() as $param) {
            if ($param->isVariadic()) {
                break;
            }

            if (array_key_exists($param->getName(), $parameters)) {
                $dependencies[] = $parameters[$param->getName()];
                continue;
            }

            $dependencyId = $this->dependencyId($param);
            if ($dependencyId !== null && $this->container->has($dependencyId)) {
                try {
                    $dependencies[] = $this->container->get($dependencyId);
                } catch (NotFoundExceptionInterface $e) {
                    // PSR-11: a missing dependency is not a "not found" error for the requested entry
                    throw new ContainerException(
                        "Unable to resolve the dependency '$dependencyId': " . $e->getMessage(),
                        0,
                        $e
                    );
                }
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();
                continue;
            }

            if ($dependencyId !== null && $param->allowsNull()) {
                $dependencies[] = null;
                continue;
            }

            throw new ContainerException(
                "Unable to resolve the parameter '\${$param->getName()}'"
                    . " in the class '{$constructor->getDeclaringClass()->getName()}'"
            );
        }
        return $dependencies;
    }

    /**
     * Container id of a constructor parameter: the name of its class or interface type.
     * Null for untyped, built-in, union and intersection types.
     */
    protected function dependencyId(ReflectionParameter $param): ?string
    {
        $type = $param->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return $type->getName();
    }

    /**
     * Human readable name of an entry; with $unique, a key identifying the entry itself.
     */
    private function describe(mixed $entry, bool $unique = false): string
    {
        if (is_string($entry)) {
            return $entry;
        }

        if ($entry instanceof Closure) {
            return 'Closure' . ($unique ? '#' . spl_object_id($entry) : '');
        }

        if (is_array($entry) && array_is_list($entry) && isset($entry[0], $entry[1]) && is_string($entry[1])) {
            $target = $entry[0];
            if (is_object($target)) {
                return $target::class . ($unique ? '#' . spl_object_id($target) : '') . '::' . $entry[1];
            }
            if (is_string($target)) {
                return $target . '::' . $entry[1];
            }
        }

        return get_debug_type($entry);
    }
}
