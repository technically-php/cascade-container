<img src="https://github.com/user-attachments/assets/bc77d08e-8926-4734-9a59-7104775b1a99" alt="Project logo: image showing an onion with the text Technically Cascade Contianer">

# Technically Cascade Container

🧅 `Technically\CascadeContainer` is simple yet powerful PSR-11 based service container implementation with layers and dependencies auto-wiring.

[![Test](https://github.com/technically-php/cascade-container/actions/workflows/test.yml/badge.svg)](https://github.com/technically-php/cascade-container/actions/workflows/test.yml)

### Philosophy

- [PSR Container][psr-11] compatibility
- [Semantic Versioning](http://semver.org/)
- PHP 8.0+
- Minimal yet elegant API

### Features

- Inherits services from a parent PSR-11 Service Container
- [Can be forked into a new isolated container](#isolated-layers-forked-from-the-service-container), inheriting all services definitions from the original container
- [PSR Container][psr-11] compatibility
- Autowiring &mdash; automatic dependencies resolution
- Full PHP 8.0+ features support for auto-wiring (e.g. union types)


```php
use Technically\CascadeContainer\CascadeContainer;

$container = new CascadeContainer();

$container->set('config', $config);

// Lazy-evaluated services
$container->deferred('mailer', function () {
   // lazily initialize mailer service here
   $mailer =  /* ... */;

   return $mailer;
});

// On-demand object factories (executes every time 'request' is obtained from the container)
$container->factory('request', fn () => $requestFactory->createRequest());

// ✨ CASCADING LAYERS ✨

// Fork the container into an isolated layer, inheriting everything from above.
// Override services or define new ones. The changes won't affect the parent $container instance.
$environment = $container->cascade();

// For example, we want to use a different mailer implementation in the tests environment:
$environment->deferred('mailer', fn () => new NullMailer());
```

Usage
-----

### Installation

Use [composer](http://getcomposer.org/).

```sh
composer require technically/cascade-container
```

### Basics

Checking presence, getting and setting service instances to the service container.

- `::get(string $id): mixed` &mdash; Get a service from the container by its name
- `::has(string $id): bool` &mdash; Check if there is a service defined in the container with the given name
- `::set(string $id, mixed $instance): void` &mdash; Define a service instance with the given name to the container 

```php
<?php

use Technically\CascadeContainer\CascadeContainer;

$container = new CascadeContainer();

// Set a service instance to the container
$container->set('acme', new AcmeService());

// Check if there is a service binding for the given service  
echo $container->has('acme') ? 'ACME service is defined' : 'Nope';

// Get a service from container
$acme = $container->get('acme');
$acme->orderProducts();
```


#### Using abstract interfaces

It's handy to bind services by their abstract interfaces
to explicitly declare its interface on both definition and consumer sides.

```php
<?php

/** @var $container \Technically\CascadeContainer\CascadeContainer */

// Definition:
// Note we bind an instance by its **abstract** interface.
// This way you force consumers to not care about implementation details, but rely on the interface. 
$container->set(\Psr\Log\LoggerInterface::class, $myLogger);

// Consumer:
// Then you have a consumer that needs a logger implementation,
// but doesn't care on details. It can use any PSR-compatible logger.
$logger = $container->get(\Psr\Log\LoggerInterface::class);
assert($logger instanceof \Psr\Log\LoggerInterface);
$logger->info('Nice!');
```



### Aliases

Sometimes you may also want to bind the same service by different IDs.
You can use aliases for that:

- `::alias(string $serviceId, string $alias): void` &mdash; Allow accessing an existing service by its new alias name

```php
<?php
/** @var $container \Technically\CascadeContainer\CascadeContainer */

$container->set(\Psr\Log\LoggerInterface::class, $myLogger);
$container->alias(\Psr\Log\LoggerInterface::class, alias: 'logger');

$logger = $container->get(\Psr\Log\LoggerInterface::class);
// ... or 
$logger = $container->get('logger'); // 100% equivalent

$logger->info('Nice!');
```


### Deferred resolvers

You can declare a service by providing a deferred resolver function for it.
The service container will call that function for the first time the service 
is requested and remember the result.

This pattern is often called *lazy initialization*.

- `::deferred(string $serviceId, callable $resolver): void` &mdash; Provide a deferred resolver for the given service name.

*Note: the callback function parameters are auto-wired the same way as with the `->call()` API.*

```php
<?php
/** @var $container \Technically\CascadeContainer\CascadeContainer */

$container->deferred('connection', function (ConnectionManager $manager) {
    return $manager->initializeConnection();
});

// Consumer:
$connection = $container->get('connection'); // The connection object
$same_connection = $container->get('connection'); // The same connection object

assert($connection === $same_connection); // The same instance
```


### Factories

You can also provide a factory function to be used to construct a new service instance
every time it is requested. 

It works very similarly to `->deferred()`, but calls the factory function every time.

- `::factory(string $serviceId, callable $factory): void` &mdash; Bind a service to a factory function to be called every time it is requested.

*Note: the callback function parameters are auto-wired the same way as with the `->call()` API.*

```php
<?php
/** @var $container \Technically\CascadeContainer\CascadeContainer */
// Definition:
$container->factory('request', function (RequestFactory $factory) {
    return $factory->createRequest();
});

// Consumer:
$request = $container->get('request');
$another = $container->get('request');

assert($request !== $another); // Different instances
```



### Extending a service

Sometimes it is necessary to extend/decorate an existing service by changing it or wrapping it into a decorator.

- `::extend(string $serviceId, callable $extension): void` &mdash; Extend an existing service by providing a transformation function.

  * Whatever the callback function returns will replace the previous instance.
  * If the service being extended is defined via a deferred resolver, the extension will become a deferred resolver too.
  * If the service being extended is defined as a factory, the extension will become a factory too.

```php
<?php
/** @var $container \Technically\CascadeContainer\CascadeContainer */

// Definition:
$container->deferred('cache', function () {
    return new RedisCache('127.0.0.1');
}); 

// Wrap the caching service with a logging decorator
$container->extend('cache', function(RedisCache $cache, LoggerInterface $logger) { 
    return new LoggingCacheDecorator($cache, $logger);
});

// Consumer:
$cache = $container->get('cache'); // LoggingCacheDecorator object
// Uses cache seamlessly as before (implying that RedisCache and LoggingCacheDecorator have the same interface)
```


### Isolated layers forked from the service container

Sometimes it is necessary to create an isolated instance of the service container,
inheriting its configured services and allowing to define more, without affecting 
the parent container.

Think of it as JavaScript variables scopes: a nested scope inherits all the variables from the parent scope.
But defining new scope variables won't modify the parent scope. That's it.

- `::cascade(): CascadeContainer` &mdash; Create a new instance of the service container, inheriting all its defined services.

```php
$project = new CascadeContainer();
$project->set('configuration', $config);

$environment = $project->cascade(); // MAGIC! ✨

// Override existing services. It does not affect 'configuration' service in the parent container.
$environment->set('configuration', $moduleConfig); 

// Define new services. They'll only exist on the current layer.
$environment->factory('request', function () {
    // ...
});
// and so on

assert($project->get('configuration') !== $environment->get('configuration')); // Parent service "configuration" instance remained unchanged
```      


### Auto-wiring dependencies

#### Construct a class instance

You can construct any class instance automatically injecting class-hinted dependencies from the service container.
It will try to resolve dependencies from the container or construct them resolving their dependencies recursively.

- `::construct(string $className, array $parameters = []): mixed` &mdash; Create a new instance of the given class
  auto-wiring its dependencies from the service container.

```php
<?php
/** @var $container \Technically\CascadeContainer\CascadeContainer */

// Class we need to inject dependencies into
class LoggingCacheDecorator {
    public function __construct(CacheInterface $cache, LoggerInterface $logger, array $options = []) {
        // initialize
    }
}

$container->set(LoggerInterface::class, $logger);
$container->set(CacheInterface::class, $cache);

// Consumer:
$cache = $container->construct(LoggingCacheDecorator::class);
// you can also provide constructor arguments in the second parameter:
$cache = $container->construct(LoggingCacheDecorator::class, ['options' => ['level' => 'debug']]);
```


#### Calling a method

You can call *any [callable]* auto-wiring its dependencies from the service container.

- `::call(callable $callable, array $parameters = []): mixed` &mdash; Call the given callable auto-wiring its dependencies from the service container.

```php
<?php
/** @var $container RockSymphony\ServiceContainer\ServiceContainer */

class MyController 
{
    public function showPost(string $url, PostsRepository $posts, TemplateEngine $templates)
    {
        $post = $posts->findByUrl($url);
        
        return $templates->render('post.html', ['post' => $post]); 
    }
}

$container->call([new MyController(), 'showPost'], ['url' => '/hello-world']);
```

You can as well pass a Closure to it:

```php
$container->call(function (PostsRepository $repository) {
    $repository->erase();
});

```

License
-------

This project is licensed under the terms of the [MIT license].

Credits
-------

Implemented by 👾 [Ivan Voskoboinyk](https://voskoboinyk.com/).

[psr-11]: https://github.com/container-interop/fig-standards/blob/master/proposed/container.md
[callable]: http://php.net/manual/en/language.types.callable.php
[MIT license]: https://opensource.org/licenses/MIT
