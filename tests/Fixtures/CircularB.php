<?php

declare(strict_types=1);

namespace G\Container\Tests\Fixtures;

final class CircularB
{
    public function __construct(public readonly CircularA $a)
    {
    }
}
