<?php

declare(strict_types=1);

namespace G\Container\Tests\Fixtures;

final class ScalarDependency
{
    public function __construct(public readonly string $name)
    {
    }
}
