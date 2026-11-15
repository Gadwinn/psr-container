<?php

declare(strict_types=1);

namespace G\Container\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\{ContainerExceptionInterface, ContainerInterface, NotFoundExceptionInterface};
use G\Container\Container;
use G\Container\Exceptions\{ContainerException, NotFoundException};
use G\Container\Resolvers\ResolverInterface;
use G\Container\Tests\Fixtures\{Car, CircularA, CircularB, Engine, Invokable, ScalarDependency};
use RuntimeException;
use stdClass;

final class ContainerTest extends TestCase
{
    public function testImplementsContainerInterface(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, new Container());
    }

    /**
     * @return array<string,array{mixed}>
     */
    public static function plainValueProvider(): array
    {
        return [
            'string'           => ['value'],
            'function name'    => ['date'],
            'static callable'  => ['DateTime::createFromFormat'],
            'empty string'     => [''],
            'zero'             => [0],
            'false'            => [false],
            'array'            => [['a' => 1]],
            'object'           => [new stdClass()],
            'invokable object' => [new Invokable()],
        ];
    }

    #[DataProvider('plainValueProvider')]
    public function testPlainValuesAreReturnedAsIs(mixed $value): void
    {
        $container = (new Container())->set('id', $value);

        $this->assertTrue($container->has('id'));
        $this->assertSame($value, $container->get('id'));
    }

    public function testInvokableObjectIsNotCalled(): void
    {
        $invokable = new Invokable();

        (new Container())->set('id', $invokable)->get('id');

        $this->assertSame(0, $invokable->calls);
    }

    public function testHas(): void
    {
        $container = (new Container())->set('id', 'value');

        $this->assertTrue($container->has('id'));
        $this->assertFalse($container->has('unknown'));
    }

    public function testGetUnknownEntryThrowsNotFoundException(): void
    {
        $container = new Container();

        try {
            $container->get('unknown');
            $this->fail('An exception was expected');
        } catch (NotFoundException $e) {
            $this->assertInstanceOf(NotFoundExceptionInterface::class, $e);
            $this->assertInstanceOf(ContainerExceptionInterface::class, $e);
        }
    }

    public function testUnregisteredClassIsNotFound(): void
    {
        $container = new Container();

        $this->assertFalse($container->has(Engine::class));
        $this->expectException(NotFoundExceptionInterface::class);

        $container->get(Engine::class);
    }

    public function testSetNullRemovesTheEntry(): void
    {
        $container = (new Container())->set('id', 'value');
        $container->get('id');

        $container->set('id', null);

        $this->assertFalse($container->has('id'));
        $this->expectException(NotFoundException::class);
        $container->get('id');
    }

    public function testClassNameIsInstantiatedAndShared(): void
    {
        $container = (new Container())->set(Engine::class, Engine::class);

        $engine = $container->get(Engine::class);

        $this->assertInstanceOf(Engine::class, $engine);
        $this->assertSame($engine, $container->get(Engine::class));
    }

    public function testClosureIsCalledOnceAndItsResultShared(): void
    {
        $calls = 0;
        $container = (new Container())->set('service', function () use (&$calls): stdClass {
            $calls++;
            return new stdClass();
        });

        $this->assertSame($container->get('service'), $container->get('service'));
        $this->assertSame(1, $calls);
    }

    public function testArrayCallableIsCalled(): void
    {
        $container = (new Container())->set('greeting', [new Invokable(), 'create']);

        $this->assertSame('Hello, World!', $container->get('greeting'));
    }

    public function testFactoryIsBuiltOnEveryGet(): void
    {
        $container = new Container();
        $container->set('service', fn (): stdClass => new stdClass())->isFactory();
        $container->set(Engine::class, Engine::class);
        $container->isFactory(Engine::class);

        $this->assertNotSame($container->get('service'), $container->get('service'));
        $this->assertNotSame($container->get('service'), $container->get('service'));
        $this->assertNotSame($container->get(Engine::class), $container->get(Engine::class));
    }

    public function testRedefiningAnEntryForgetsTheSharedInstanceAndTheFactoryFlag(): void
    {
        $container = (new Container())->set('service', Engine::class);
        $this->assertInstanceOf(Engine::class, $container->get('service'));

        $container->set('service', stdClass::class);
        $this->assertInstanceOf(stdClass::class, $container->get('service'));

        $container->isFactory('service');
        $container->set('service', stdClass::class);
        $this->assertSame($container->get('service'), $container->get('service'));
    }

    public function testConstructorDependenciesComeFromTheContainer(): void
    {
        $container = (new Container())
            ->set(Engine::class, Engine::class)
            ->set(Car::class, Car::class);

        $car = $container->get(Car::class);

        $this->assertInstanceOf(Car::class, $car);
        $this->assertSame($container->get(Engine::class), $car->engine);
        $this->assertSame('red', $car->color);
    }

    public function testMissingDependencyIsNotReportedAsNotFound(): void
    {
        $container = (new Container())->set(Car::class, Car::class);

        try {
            $container->get(Car::class);
            $this->fail('An exception was expected');
        } catch (ContainerExceptionInterface $e) {
            // PSR-11: the requested entry exists, so this must not be a "not found" error
            $this->assertNotInstanceOf(NotFoundExceptionInterface::class, $e);
        }
    }

    public function testUnresolvableScalarParameterThrowsContainerException(): void
    {
        $container = (new Container())->set(ScalarDependency::class, ScalarDependency::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$name');

        $container->get(ScalarDependency::class);
    }

    public function testCircularDependencyIsDetected(): void
    {
        $container = (new Container())
            ->set(CircularA::class, CircularA::class)
            ->set(CircularB::class, CircularB::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');

        $container->get(CircularA::class);
    }

    public function testEntryCanBeResolvedAgainAfterAFailure(): void
    {
        $fail = true;
        $container = (new Container())->set('service', function () use (&$fail): stdClass {
            if ($fail) {
                throw new RuntimeException('Not ready');
            }
            return new stdClass();
        });

        try {
            $container->get('service');
            $this->fail('An exception was expected');
        } catch (RuntimeException $e) {
            $this->assertSame('Not ready', $e->getMessage());
        }

        $fail = false;
        $this->assertInstanceOf(stdClass::class, $container->get('service'));
    }

    public function testCustomResolver(): void
    {
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->once())->method('isResolvable')->willReturn(true);
        $resolver->expects($this->once())->method('resolve')->with('anything')->willReturn('resolved');

        $container = (new Container($resolver))->set('id', 'anything');

        $this->assertSame('resolved', $container->get('id'));
        $this->assertSame('resolved', $container->get('id'));
    }
}
