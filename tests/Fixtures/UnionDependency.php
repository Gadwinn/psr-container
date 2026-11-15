<?php

declare(strict_types=1);

namespace G\Container\Tests\Fixtures;

final class UnionDependency
{
    public function __construct(public readonly Engine|string $value)
    {
    }
}
