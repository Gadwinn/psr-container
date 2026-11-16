<?php

declare(strict_types=1);

namespace G\Container\Tests\Fixtures;

final class Garage
{
    public function __construct(public readonly Dashboard $dependency)
    {
    }
}
