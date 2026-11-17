<?php

declare(strict_types=1);

namespace G\Container\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\{ContainerExceptionInterface, ContainerInterface, NotFoundExceptionInterface};
use G\Container\Container;
use G\Container\Exception\{ContainerException, NotFoundException};
use G\Container\Resolver\ResolverInterface;
use G\Container\Tests\Fixtures\{AbstractService, Car, CircularA, CircularB, Dashboard, Engine, EngineInterface};
use G\Container\Tests\Fixtures\{Fuel, Garage, Honks, Invokable, OptionalDependency, ScalarDependency};
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

    public function testUndefinedClassIsAutowiredAndShared(): void
    {
        $container = new Container();

        $this->assertTrue($container->has(Car::class));
        $this->assertFalse($container->isDefined(Car::class));

        $car = $container->get(Car::class);

        $this->assertInstanceOf(Car::class, $car);
        $this->assertSame($car, $container->get(Car::class));
        $this->assertSame($container->get(Engine::class), $car->engine);
    }

    public function testDefinedEntryWinsOverAutowiring(): void
    {
        $engine = new Engine();
        $container = (new Container())->set(Engine::class, fn (): Engine => $engine);

        $this->assertSame($engine, $container->get(Car::class)->engine);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notInstantiableTypes(): array
    {
        return [
            'interface' => [EngineInterface::class],
            'abstract class' => [AbstractService::class],
            'enum' => [Fuel::class],
            'trait' => [Honks::class],
        ];
    }

    #[DataProvider('notInstantiableTypes')]
    public function testUndefinedNotInstantiableTypeIsNotFound(string $id): void
    {
        $container = new Container();

        $this->assertFalse($container->has($id));
        $this->expectException(NotFoundExceptionInterface::class);
        $this->expectExceptionMessage("define the implementation to use with set('$id', ...)");

        $container->get($id);
    }

    public function testCircularDependencyIsDetectedThroughAutowiring(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');

        (new Container())->get(CircularA::class);
    }

    public function testAutowiringFailureKeepsTheChainOfClasses(): void
    {
        try {
            (new Container())->get(Garage::class);
            $this->fail('An exception was expected');
        } catch (ContainerExceptionInterface $e) {
            $this->assertNotInstanceOf(NotFoundExceptionInterface::class, $e);
            $this->assertStringContainsString("'" . Dashboard::class . "' of the class '" . Garage::class . "'", $e->getMessage());
            $this->assertStringContainsString('$dependency', $e->getMessage());
            $this->assertStringContainsString("the class '" . Dashboard::class . "'", $e->getMessage());
        }
    }

    public function testOptionalParameterKeepsItsDefaultRatherThanBeingAutowired(): void
    {
        $object = (new Container())->get(OptionalDependency::class);

        $this->assertNull($object->engine);
        $this->assertNull($object->other);
    }

    public function testContainerIsAvailableUnderItsInterfaceAndClass(): void
    {
        $container = new Container();

        $this->assertTrue($container->has(ContainerInterface::class));
        $this->assertTrue($container->isDefined(ContainerInterface::class));
        $this->assertSame($container, $container->get(ContainerInterface::class));
        $this->assertSame($container, $container->get(Container::class));
    }

    public function testContainerInterfaceCanBeRedefined(): void
    {
        $other = new Container();
        $container = (new Container())->set(ContainerInterface::class, $other);

        $this->assertSame($other, $container->get(ContainerInterface::class));
    }

    public function testFactoryReceivesTheContainerAndAutowiredServices(): void
    {
        $container = new Container();
        $container->set('service', fn (ContainerInterface $c, Engine $engine, Container $self): array => [$c, $engine, $self]);

        [$c, $engine, $self] = $container->get('service');

        $this->assertSame($container, $c);
        $this->assertSame($container, $self);
        $this->assertSame($container->get(Engine::class), $engine);
    }

    public function testUntypedFirstParameterOfAFactoryReceivesTheContainer(): void
    {
        $container = new Container();
        $container->set('service', fn ($c, Engine $engine): array => [$c, $engine]);

        [$c, $engine] = $container->get('service');

        $this->assertSame($container, $c);
        $this->assertSame($container->get(Engine::class), $engine);
    }

    public function testUntypedParameterAfterTheFirstIsNotResolved(): void
    {
        $container = new Container();
        $container->set('service', fn (Engine $engine, $other): array => [$engine, $other]);

        $this->expectException(ContainerException::class);
        $container->get('service');
    }

    public function testArrayCallableFactoryReceivesTheContainer(): void
    {
        $factory = new class {
            public function create(ContainerInterface $container): Car
            {
                return new Car($container->get(Engine::class), 'blue');
            }
        };
        $container = (new Container())->set(Car::class, [$factory, 'create']);

        $this->assertSame('blue', $container->get(Car::class)->color);
    }

    public function testFactoryWithUnresolvableParameterThrowsContainerException(): void
    {
        $container = (new Container())->set('service', fn (string $name): string => $name);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$name');

        $container->get('service');
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
        $container = (new Container())->set(Dashboard::class, Dashboard::class);

        try {
            $container->get(Dashboard::class);
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
