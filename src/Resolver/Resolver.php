<?php

declare(strict_types=1);

namespace G\Container\Resolver;

use Closure;
use G\Container\Container;
use G\Container\Exception\{ContainerException, NotFoundException};
use Psr\Container\{ContainerInterface, NotFoundExceptionInterface};
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Turns a container entry into a value:
 *  - a factory (a Closure or an array callable such as [$object, 'method']) is called, its
 *    parameters being resolved like constructor parameters (a parameter typed with
 *    Psr\Container\ContainerInterface receives the container, and so does an untyped
 *    first parameter);
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
     * @param array<string|int,mixed> $parameters Arguments, by parameter name or position.
     *
     * @return mixed
     * @throws ContainerException
     */
    protected function resolveCallable(callable $callable, array $parameters = []): mixed
    {
        $function = Closure::fromCallable($callable);
        $reflection = new ReflectionFunction($function);

        // Usual convention of the PHP containers: fn ($c) => ... receives the container
        $first = $reflection->getParameters()[0] ?? null;
        if (
            $first !== null && !$first->hasType() && !$first->isVariadic()
            && !array_key_exists(0, $parameters) && !array_key_exists($first->getName(), $parameters)
        ) {
            $parameters[0] = $this->container;
        }

        return $function(...$this->resolveParameters($reflection, $parameters));
    }

    /**
     * @param class-string $id
     * @param array<string|int,mixed> $parameters Constructor arguments, by parameter name or position.
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
     * Arguments of a constructor or a factory, in this order of priority: the given
     * parameters, the entries defined in the container, the default values, the classes
     * the container can autowire, null for nullable class types.
     *
     * @param ReflectionFunctionAbstract $function
     * @param array<string|int,mixed> $parameters Arguments, by parameter name or position.
     *
     * @return list<mixed>
     * @throws ContainerException
     */
    protected function resolveParameters(ReflectionFunctionAbstract $function, array $parameters): array
    {
        $dependencies = [];
        foreach ($function->getParameters() as $param) {
            if ($param->isVariadic()) {
                break;
            }

            if (array_key_exists($param->getName(), $parameters)) {
                $dependencies[] = $parameters[$param->getName()];
                continue;
            }

            if (array_key_exists($param->getPosition(), $parameters)) {
                $dependencies[] = $parameters[$param->getPosition()];
                continue;
            }

            $dependencyId = $this->dependencyId($param);
            $defined = $dependencyId !== null && $this->isDefined($dependencyId);

            // An optional parameter keeps its default value rather than being autowired
            if (
                $dependencyId !== null && ($defined || !$param->isDefaultValueAvailable())
                && $this->container->has($dependencyId)
            ) {
                $dependencies[] = $this->getDependency($dependencyId, $function);
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
                "Unable to resolve the parameter '\${$param->getName()}' of {$this->describeFunction($function)}"
            );
        }
        return $dependencies;
    }

    /**
     * Read a dependency from the container; a failure is reported as a ContainerException
     * (PSR-11: a missing dependency is not a "not found" error for the requested entry),
     * whose message keeps the chain of the entries being built.
     *
     * @throws ContainerException
     */
    protected function getDependency(string $id, ReflectionFunctionAbstract $function): mixed
    {
        try {
            return $this->container->get($id);
        } catch (ContainerException | NotFoundExceptionInterface $e) {
            throw new ContainerException(
                "Unable to resolve the dependency '$id' of {$this->describeFunction($function)}: "
                    . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Tell whether an identifier is explicitly defined in the container, as opposed to a
     * class it would only autowire. Without that information (other PSR-11 containers),
     * every entry the container has counts as defined.
     */
    protected function isDefined(string $id): bool
    {
        return $this->container instanceof Container
            ? $this->container->isDefined($id)
            : $this->container->has($id);
    }

    /**
     * Human readable name of a constructor or a factory.
     */
    protected function describeFunction(ReflectionFunctionAbstract $function): string
    {
        if ($function instanceof ReflectionMethod && $function->isConstructor()) {
            return "the class '{$function->getDeclaringClass()->getName()}'";
        }

        $class = $function->getClosureScopeClass();
        $name = $function->getName();
        if (str_contains($name, '{closure')) {
            return 'the factory Closure' . ($class !== null ? " in '{$class->getName()}'" : '');
        }

        return "the factory '" . ($class !== null ? $class->getName() . '::' : '') . "$name()'";
    }

    /**
     * Container id of a parameter: the name of its class or interface type.
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
