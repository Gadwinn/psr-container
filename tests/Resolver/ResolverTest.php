<?php

declare(strict_types=1);

namespace G\Container\Tests\Resolver;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use G\Container\Container;
use G\Container\Exception\{ContainerException, NotFoundException};
use G\Container\Resolver\Resolver;
use G\Container\Tests\Fixtures\{
    AbstractService,
    Car,
    Engine,
    EngineInterface,
    Invokable,
    OptionalDependency,
    ScalarDependency,
    UnionDependency
};
use stdClass;

final class ResolverTest extends TestCase
{
    private Container $container;
    private Resolver $resolver;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->resolver = new Resolver($this->container);
    }

    /**
     * @return array<string,array{mixed,bool}>
     */
    public static function resolvableProvider(): array
    {
        return [
            'class name'               => [stdClass::class, true],
            'closure'                  => [fn (): string => 'Hello', true],
            'array callable'           => [[new Invokable(), 'create'], true],
            'static array callable'    => [[\DateTime::class, 'createFromFormat'], true],
            'unknown class'            => ['UnknownClass', false],
            'interface name'           => [EngineInterface::class, false],
            'function name'            => ['strlen', false],
            'static callable string'   => ['DateTime::createFromFormat', false],
            'invokable object'         => [new Invokable(), false],
            'plain object'             => [new stdClass(), false],
            'plain array'              => [['a', 'b'], false],
            'integer'                  => [42, false],
            'null'                     => [null, false],
        ];
    }

    #[DataProvider('resolvableProvider')]
    public function testIsResolvable(mixed $entry, bool $expected): void
    {
        $this->assertSame($expected, $this->resolver->isResolvable($entry));
    }

    public function testResolveClassWithoutConstructor(): void
    {
        $this->assertInstanceOf(Engine::class, $this->resolver->resolve(Engine::class));
    }

    public function testResolveClassWithNamedParameters(): void
    {
        $engine = new Engine();

        $car = $this->resolver->resolve(Car::class, ['engine' => $engine, 'color' => 'blue']);

        $this->assertInstanceOf(Car::class, $car);
        $this->assertSame($engine, $car->engine);
        $this->assertSame('blue', $car->color);
    }

    public function testGivenParameterWinsOverTheContainer(): void
    {
        $this->container->set(Engine::class, Engine::class);
        $engine = new Engine();

        $car = $this->resolver->resolve(Car::class, ['engine' => $engine]);

        $this->assertInstanceOf(Car::class, $car);
        $this->assertSame($engine, $car->engine);
    }

    public function testResolveScalarParameter(): void
    {
        $object = $this->resolver->resolve(ScalarDependency::class, ['name' => 'John']);

        $this->assertInstanceOf(ScalarDependency::class, $object);
        $this->assertSame('John', $object->name);
    }

    public function testOptionalDependenciesFallBackToNullOrDefault(): void
    {
        $object = $this->resolver->resolve(OptionalDependency::class);

        $this->assertInstanceOf(OptionalDependency::class, $object);
        $this->assertNull($object->engine);
        $this->assertNull($object->other);
    }

    public function testUnionTypeIsNotAutowired(): void
    {
        $this->container->set(Engine::class, Engine::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$value');

        $this->resolver->resolve(UnionDependency::class);
    }

    public function testResolveNotInstantiableClassThrowsContainerException(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('not instantiable');

        $this->resolver->resolve(AbstractService::class);
    }

    public function testResolveNotResolvableEntryThrowsNotFoundException(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('UnknownClass');

        $this->resolver->resolve('UnknownClass');
    }

    public function testResolveCallable(): void
    {
        $this->assertSame('Hello, World!', $this->resolver->resolve([new Invokable(), 'create']));
    }

    public function testResolveCallableWithParameters(): void
    {
        $this->assertSame('Hello, John!', $this->resolver->resolve([new Invokable(), 'create'], ['John']));
        $this->assertSame('Hello, Jane!', $this->resolver->resolve(fn (string $name) => "Hello, $name!", ['Jane']));
    }

    public function testSameCallableCanBeResolvedSeveralTimes(): void
    {
        $factory = fn (): stdClass => new stdClass();

        $this->assertNotSame($this->resolver->resolve($factory), $this->resolver->resolve($factory));
    }

    public function testCallableCallingItselfIsACircularDependency(): void
    {
        $resolver = $this->resolver;
        $factory = function () use (&$factory, $resolver): mixed {
            return $resolver->resolve($factory);
        };

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');

        $resolver->resolve($factory);
    }
}
