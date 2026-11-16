<?php

declare(strict_types=1);

namespace G\Container\Resolver;

use G\Container\Exception\{ContainerException, NotFoundException};

interface ResolverInterface
{
    /**
     * Build the value of a resolvable entry.
     *
     * @param mixed $entry
     * @param array<string|int,mixed> $parameters
     *
     * @return mixed
     * @throws NotFoundException If the entry is not resolvable.
     * @throws ContainerException If the entry cannot be built.
     */
    public function resolve($entry, array $parameters = []): mixed;

    /**
     * Tell whether an entry has to be built (factory, class name) rather than returned as is.
     *
     * @param mixed $entry
     *
     * @return bool
     */
    public function isResolvable($entry): bool;
}
