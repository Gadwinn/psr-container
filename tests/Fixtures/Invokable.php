<?php

declare(strict_types=1);

namespace G\Container\Tests\Fixtures;

final class Invokable
{
    public int $calls = 0;

    public function __invoke(): string
    {
        $this->calls++;
        return 'invoked';
    }

    public function create(string $name = 'World'): string
    {
        return 'Hello, ' . $name . '!';
    }
}
