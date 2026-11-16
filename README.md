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

### Autowiring

A class which was never defined with `set()` is built on first `get()` when it is
instantiable, then shared like any other service: there is no need to declare classes whose
constructor the container can resolve on its own.

```php
$container = new Container();

$container->has(Mailer::class);   // true: Mailer is an instantiable class
$container->get(Mailer::class);   // built once, then shared
```

Interfaces, abstract classes, enums and traits cannot be instantiated: `has()` returns `false`
and `get()` throws a `NotFoundException` asking to define the implementation to use:

```php
$container->set(LoggerInterface::class, FileLogger::class);
```

The container itself is available under `Psr\Container\ContainerInterface` (and its own class
name), unless you define that identifier yourself. `isDefined($id)` tells whether an
identifier was defined with `set()` (or designates the container), as opposed to a class which
would only be autowired.

### Constructor and factory parameters

The parameters of a constructor (class name entries, autowired classes) and of a factory
(Closure or array callable) are resolved in this order:

1. a parameter whose type is a class or an interface defined in the container receives that entry
   (a parameter typed `ContainerInterface` therefore receives the container);
2. otherwise the default value of the parameter is used, if any: an optional parameter is
   never autowired;
3. otherwise a parameter whose type is an instantiable class is autowired;
4. otherwise `null` is used when the parameter is a nullable class or interface;
5. otherwise a `ContainerException` is thrown, naming the parameter and the class or factory.

Union and intersection types are not autowired.

```php
final class Mailer
{
    public function __construct(private LoggerInterface $logger, private int $retries = 3)
    {
    }
}

$container->set(LoggerInterface::class, fn () => new FileLogger('/var/log/app.log'));

$container->get(Mailer::class); // Mailer built with the FileLogger and 3 retries
```

Use a factory for anything autowiring cannot guess; it receives the container, and any other
service it asks for by type:

```php
use Psr\Container\ContainerInterface;

$container->set(
    Mailer::class,
    fn (ContainerInterface $c) => new Mailer($c->get(LoggerInterface::class), 5)
);

$container->set(Report::class, fn (Mailer $mailer, Clock $clock) => new Report($mailer, $clock->now()));
```

When a dependency cannot be built, the message of the `ContainerException` keeps the chain of
the entries being built (`Unable to resolve the dependency 'B' of the class 'A': ...`).

### Errors

| Situation | Exception |
|---|---|
| Unknown identifier, undefined interface, abstract class, enum or trait | `G\Container\Exception\NotFoundException` (`Psr\Container\NotFoundExceptionInterface`) |
| Entry that cannot be built: unresolvable parameter, missing dependency, class not instantiable, circular dependency | `G\Container\Exception\ContainerException` (`Psr\Container\ContainerExceptionInterface`) |

As required by PSR-11, a missing *dependency* of an existing entry is reported as a
`ContainerException`, not as a `NotFoundException`. Exceptions thrown by your own factories
and constructors are not wrapped.

### Custom resolver

The way entries are built can be replaced by passing your own
`G\Container\Resolver\ResolverInterface` to the constructor:

```php
$container = new Container($myResolver);
```

## Development

```bash
composer install
composer test-all   # security audit, code style, static analysis, unit tests, magic numbers
```
