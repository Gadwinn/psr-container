# PSR-11 Container

A small PHP 8.3+ dependency injection container implementing [PSR-11](https://www.php-fig.org/psr/psr-11/), with shared services, factories and constructor autowiring.

## Installation

```bash
composer require gadwinn/psr-container
```

## Usage

### Defining and reading entries

```php
use G\Container\Container;

$container = new Container();

$container
    ->set('app.name', 'My application')                 // plain value
    ->set(Mailer::class, Mailer::class)                 // class name: instantiated on first get()
    ->set(Clock::class, fn () => new SystemClock());    // factory: called on first get()

$container->get('app.name');        // 'My application'
$container->get(Mailer::class);     // Mailer instance
$container->has('unknown');         // false
```

`set()` with `null` removes the entry. Setting an identifier again replaces its definition and
forgets the instance built from the previous one.

### What gets built, what is returned as is

| Entry value | Behaviour of `get()` |
|---|---|
| `Closure` | called, result returned |
| array callable (`[$object, 'method']`, `[Foo::class, 'create']`) | called, result returned |
| name of an existing class | instantiated (see autowiring) |
| anything else | returned as is |

A string is only ever treated as a class name: a value such as `'date'` or `'Foo::bar'` is
never called, even though PHP considers it callable. Likewise, an object is always returned
as is, even when it is invokable. Wrap it in a closure if you need it to act as a factory.

### Shared services and factories

By default the value built for an entry is shared: it is built once and the same value is
returned by every `get()`. Flag an entry with `isFactory()` to build a new value each time:

```php
$container->set(Mailer::class, Mailer::class);                   // shared
$container->set(Message::class, Message::class)->isFactory();    // new instance on every get()
$container->isFactory(Message::class);                           // same, by identifier
```

### Constructor autowiring

When an entry is a class name, its constructor arguments are resolved in this order:

1. a parameter whose type is a class or an interface is read from the container, when the
   container has an entry with that name;
2. otherwise the default value of the parameter is used, if any;
3. otherwise `null` is used when the parameter is a nullable class or interface;
4. otherwise a `ContainerException` is thrown.

```php
final class Mailer
{
    public function __construct(private LoggerInterface $logger, private int $retries = 3)
    {
    }
}

$container
    ->set(LoggerInterface::class, fn () => new FileLogger('/var/log/app.log'))
    ->set(Mailer::class, Mailer::class);

$container->get(Mailer::class); // Mailer built with the FileLogger and 3 retries
```

Only identifiers defined with `set()` exist: a class which was never registered is not
built on the fly, and union or intersection types are not autowired. Use a factory for
anything autowiring cannot guess:

```php
$container->set(Mailer::class, fn () => new Mailer($container->get(LoggerInterface::class), 5));
```

### Errors

| Situation | Exception |
|---|---|
| Unknown identifier | `G\Container\Exceptions\NotFoundException` (`Psr\Container\NotFoundExceptionInterface`) |
| Entry that cannot be built: unresolvable parameter, missing dependency, class not instantiable, circular dependency | `G\Container\Exceptions\ContainerException` (`Psr\Container\ContainerExceptionInterface`) |

As required by PSR-11, a missing *dependency* of an existing entry is reported as a
`ContainerException`, not as a `NotFoundException`. Exceptions thrown by your own factories
and constructors are not wrapped.

### Custom resolver

The way entries are built can be replaced by passing your own
`G\Container\Resolvers\ResolverInterface` to the constructor:

```php
$container = new Container($myResolver);
```

## Development

```bash
composer install
composer test-all   # security audit, code style, static analysis, unit tests, magic numbers
```
