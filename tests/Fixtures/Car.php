<?php

declare(strict_types=1);

namespace G\Container\Tests\Fixtures;

final class Car
{
    public function __construct(
        public readonly Engine $engine,
        public readonly string $color = 'red'
    ) {
    }
}
