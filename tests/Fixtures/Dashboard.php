<?php

declare(strict_types=1);

namespace G\Container\Tests\Fixtures;

final class Dashboard
{
    public function __construct(public readonly EngineInterface $dependency)
    {
    }
}
