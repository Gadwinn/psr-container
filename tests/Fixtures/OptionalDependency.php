<?php

declare(strict_types=1);

namespace G\Container\Tests\Fixtures;

final class OptionalDependency
{
    public function __construct(
        public readonly ?EngineInterface $engine,
        public readonly ?Engine $other = null
    ) {
    }
}
