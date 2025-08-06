<?php

use Psr\Container\ContainerInterface;
use Technically\ArrayContainer\ArrayContainer;
use Technically\CascadeContainer\CascadeContainer;
use Technically\DependencyResolver\Contracts\DependencyResolver;
use Technically\NullContainer\NullContainer;

describe('CascadeContainer::__construct()', function () {
    it('should construct a new instance of CascadeContainer', function () {
        expect(new CascadeContainer())->toBeInstanceOf(CascadeContainer::class);
    });

    it('should construct a new instance of CascadeContainer with custom parent container', function () {
        $parent = new Technically\ArrayContainer\ArrayContainer([]);

        expect(new CascadeContainer($parent))->toBeInstanceOf(CascadeContainer::class);
    });

    it('should construct a new instance with initial services', function () {
        $container = new CascadeContainer([
            'logger' => function (string $message) {
                echo $message, PHP_EOL;
            },
        ]);

        expect($container->has('logger'))->toBeTrue();
        expect($container->get('logger'))->toBeCallable();
    });

    it('should construct a new instance of CascadeContainer with a custom resolver', function () {
        $resolver = new class implements DependencyResolver {
            public function resolve(string $className): mixed
            {
                throw new LogicException('Not implemented');
            }

            public function construct(string $className, array $bindings = []): mixed
            {
                throw new LogicException('Not implemented');
            }

            public function call(callable $callable, array $bindings = []): mixed
            {
                throw new LogicException('Not implemented');
            }
        };

        expect(new CascadeContainer(resolver: $resolver))->toBeInstanceOf(CascadeContainer::class);
    });
});

describe('CascadeContainer::has()', function () {
    it('should check if the service container has the given service defined', function () {
        $container = new CascadeContainer();

        expect($container->has('container'))->toBeFalse();

        $container->set('container', $container);

        expect($container->has('container'))->toBeTrue();
    });

    it('should check if the parent service container has the given service defined', function () {
        $container = new CascadeContainer(
            new ArrayContainer([
                'container' => new NullContainer(),
            ]),
        );
        expect($container->has('container'))->toBeTrue();
    });

    it('should check if the service container has a deferred resolver for the given service defined', function () {
        $container = new CascadeContainer();
        $container->deferred('container', fn () => $container);

        expect($container->has('container'))->toBeTrue();
    });

    it('should check if the service container has a factory defined for the given service defined', function () {
        $container = new CascadeContainer();
        $container->factory('container', fn () => $container);

        expect($container->has('container'))->toBeTrue();
    });

    it('should take defined aliases into account', function () {
        $container = new CascadeContainer();
        $container->factory('container', fn () => $container);
        $container->alias('container', ContainerInterface::class);

        expect($container->has(ContainerInterface::class))->toBeTrue();
    });

    it('should dynamically resolve aliases to check if the aliased service is defined', function () {
        $container = new CascadeContainer();
        $container->alias('container', ContainerInterface::class);

        expect($container->has(ContainerInterface::class))->toBeFalse();
    });
});

describe('CascadeContainer::get()', function () {
    it('should get the service instance defined on the container directly', function () {
        $container = new CascadeContainer();
        $container->set('container', $container);

        expect($container->get('container'))->toBe($container);
    });

    it('should get the service instance defined in the parent service container', function () {
        $parent = new ArrayContainer([
            'container' => new NullContainer(),
        ]);

        $container = new CascadeContainer($parent);

        expect($container->get('container'))->toBeInstanceOf(NullContainer::class);
        expect($container->get('container') === $parent->get('container'))->toBeTrue();
    });

    it('should use the deferred resolver defined for the given service', function () {
        $container = new CascadeContainer();
        $container->deferred('container', fn () => $container);

        expect($container->get('container'))->toBe($container);
    });

    it('should use the factory defined for the given service', function () {
        $container = new CascadeContainer();
        $container->factory('container', fn () => $container);

        expect($container->get('container'))->toBe($container);
    });
});

describe('CascadeContainer::set()', function () {
    it('should set the service instance', function () {
        $container = new CascadeContainer();
        $container->set('container', $container);

        expect($container->get('container'))->toBe($container);
    });

    it('should override previously defined instances', function () {
        $container = new CascadeContainer();
        $container->set('container', $container);
        $container->set('container', new NullContainer());

        expect($container->get('container'))->toBeInstanceOf(NullContainer::class);
    });

    it('should override previously defined deferred resolvers', function () {
        $container = new CascadeContainer();
        $container->deferred('container', fn () => $container);
        $container->set('container', new NullContainer());

        expect($container->get('container'))->toBeInstanceOf(NullContainer::class);
    });

    it('should override previously defined factories', function () {
        $container = new CascadeContainer();
        $container->factory('container', fn () => new NullContainer());
        $container->set('container', $container);

        expect($container->get('container'))->toBe($container);
    });

    it('should take precedence over parent container', function () {
        $container = new CascadeContainer(
            new ArrayContainer([
                'container' => new NullContainer(),
            ]),
        );
        $container->set('container', $container);

        expect($container->get('container'))->toBe($container);
    });

    it('should overwrite existing aliases', function () {
        $container = new CascadeContainer(
            new ArrayContainer([
                ContainerInterface::class => new NullContainer(),
            ]),
        );
        $container->alias(ContainerInterface::class, alias: 'container');
        $container->set('container', $container);

        expect($container->get('container'))->toBe($container);
    });
});

describe('CascadeContainer::alias()', function () {
    it('should set aliases to existing service instances', function () {
        $container = new CascadeContainer();
        $container->set(ContainerInterface::class, $container);

        $container->alias(ContainerInterface::class, alias: 'container');

        expect($container->get('container'))->toBe($container);
        expect($container->get(ContainerInterface::class))->toBe($container);
    });

    it('should set aliases to existing deferred resolvers', function () {
        $container = new CascadeContainer();
        $container->deferred(ContainerInterface::class, fn () => $container);

        $container->alias(ContainerInterface::class, alias: 'container');

        expect($container->get(ContainerInterface::class))->toBe($container);
    });

    it('should set aliases to existing service factories', function () {
        $container = new CascadeContainer();
        $container->factory(DateTime::class, fn () => new DateTime('now'));

        $container->alias(DateTime::class, alias: 'date');

        expect($container->get('date'))->toBeInstanceOf(DateTime::class);
    });
});

describe('CascadeContainer::resolver()', function () {
    it('should define a deferred resolver for the given service', function () {
        $container = new CascadeContainer();
        $container->deferred('date', fn () => new DateTime('now'));

        expect($container->get('date'))->toBeInstanceOf(DateTime::class);
        expect($container->get('date') === $container->get('date'))->toBeTrue(); // The same instance is returned every time
    });

    it('should override previously defined instances', function () {
        $container = new CascadeContainer();
        $container->set('container', $container);
        $container->deferred('container', fn () => new NullContainer());

        expect($container->get('container'))->toBeInstanceOf(NullContainer::class);
    });

    it('should override previously defined factories', function () {
        $container = new CascadeContainer();
        $container->factory('container', fn () => $container);
        expect($container->get('container'))->toBe($container);

        $container->deferred('container', fn () => new NullContainer());
        expect($container->get('container'))->toBeInstanceOf(NullContainer::class);
    });

    it('it should override previously defined (unresolved) deferred resolvers', function () {
        $container = new CascadeContainer();
        $container->deferred('container', fn () => new NullContainer());
        $container->deferred('container', fn () => $container);

        expect($container->get('container'))->toBe($container);
    });

    it('it should override previously defined resolved deferred resolvers', function () {
        $container = new CascadeContainer();

        $container->deferred('container', fn () => new NullContainer());
        expect($container->get('container'))->toBeInstanceOf(NullContainer::class);

        $container->deferred('container', fn () => $container);
        expect($container->get('container'))->toBeInstanceOf(CascadeContainer::class);
    });

    it('should take precedence over parent container', function () {
        $container = new CascadeContainer(
            new ArrayContainer([
                'container' => new NullContainer(),
            ]),
        );
        $container->deferred('container', fn () => $container);

        expect($container->get('container'))->toBe($container);
    });

    it('should autowire resolver function parameters', function () {
        $container = new CascadeContainer();
        $container->set(DateTime::class, new DateTime('2025-01-01T12:00:00Z'));

        $container->deferred('logger', function (DateTime $date) {
            expect($date)->toEqual(new DateTime('2025-01-01T12:00:00Z'));

            return function (string $message) use ($date) {
                error_log(sprintf('[%s] %s', $date->format('Y-m-d H:i:s'), $message));
            };
        });

        $container->get('logger')('Hello world!');
    });
});

describe('CascadeContainer::factory()', function () {
    it('should define a factory for the given service', function () {
        $container = new CascadeContainer();
        $container->factory('date', fn () => new DateTime('now'));

        expect($container->get('date'))->toBeInstanceOf(DateTime::class);
        expect($container->get('date') === $container->get('date'))->toBeFalse(); // A new instance is returned every time
    });

    it('should override previously defined instances', function () {
        $container = new CascadeContainer();
        $container->set('container', $container);
        $container->factory('container', fn () => new NullContainer());

        expect($container->get('container'))->toBeInstanceOf(NullContainer::class);
    });

    it('should override previously defined factories', function () {
        $container = new CascadeContainer();
        $container->factory('container', fn () => $container);
        $container->factory('container', fn () => new NullContainer());

        expect($container->get('container'))->toBeInstanceOf(NullContainer::class);
    });

    it('it should override previously defined (unresolved) deferred resolvers', function () {
        $container = new CascadeContainer();
        $container->deferred('container', fn () => new NullContainer());
        $container->factory('container', fn () => $container);

        expect($container->get('container'))->toBe($container);
    });

    it('it should override previously defined resolved deferred resolvers', function () {
        $container = new CascadeContainer();

        $container->deferred('container', fn () => new NullContainer());
        expect($container->get('container'))->toBeInstanceOf(NullContainer::class);

        $container->factory('container', fn () => $container);
        expect($container->get('container'))->toBe($container);
    });

    it('should take precedence over parent container', function () {
        $container = new CascadeContainer(
            new ArrayContainer([
                'container' => new NullContainer(),
            ]),
        );
        $container->factory('container', fn () => $container);

        expect($container->get('container'))->toBe($container);
    });

    it('should autowire factory function parameters', function () {
        $container = new CascadeContainer();
        $container->set(DateTime::class, new DateTime('2025-01-01T12:00:00Z'));

        $container->factory('logger', function (DateTime $date) {
            expect($date)->toEqual(new DateTime('2025-01-01T12:00:00Z'));

            return function (string $message) use ($date) {
                error_log(sprintf('[%s] %s', $date->format('Y-m-d H:i:s'), $message));
            };
        });

        $container->get('logger')('Hello world!');
    });
});

describe('CascadeContainer::extend()', function () {
    it('should extend the existing service instance', function () {
        $container = new CascadeContainer();
        $container->set('container', new NullContainer());

        expect($container->get('container'))->toBeInstanceOf(NullContainer::class);

        $container->extend('container', function ($container) {
            expect($container)->toBeInstanceOf(NullContainer::class);

            return new CascadeContainer(parent: $container);
        });

        expect($container->get('container'))->toBeInstanceOf(CascadeContainer::class);
    });

    it('should extend deferred service resolvers', function () {
        $container = new CascadeContainer();
        $container->deferred('container', fn () => new NullContainer());

        $container->extend('container', function ($container) {
            expect($container)->toBeInstanceOf(NullContainer::class);

            return new CascadeContainer(parent: $container);
        });

        expect($container->get('container'))->toBeInstanceOf(CascadeContainer::class);
    });

    it('should extend service factories', function () {
        $container = new CascadeContainer();

        $container->factory('date', fn () => new DateTime('now'));

        $container->extend('date', function ($date) {
            expect($date)->toBeInstanceOf(DateTime::class);

            $date->setTimezone(new DateTimeZone('Europe/Madrid'));

            return $date;
        });

        expect($container->get('date'))->toBeInstanceOf(DateTime::class);
        expect($container->get('date')->getTimeZone())->toEqual(new DateTimeZone('Europe/Madrid'));

        expect($container->get('date') !== $container->get('date'))->toBeTrue();
    });

    it('should extend services by their aliases', function () {
        $container = new CascadeContainer();

        $container->set('container', $container);
        $container->alias('container', alias: ContainerInterface::class);

        $container->extend(ContainerInterface::class, function ($container) {
            expect($container)->toBeInstanceOf(CascadeContainer::class);

            assert($container instanceof CascadeContainer);

            return $container->cascade();
        });

        expect($container->get('container'))->toBeInstanceOf(CascadeContainer::class);
        expect($container->get(ContainerInterface::class))->toBeInstanceOf(CascadeContainer::class);

        expect($container->get('container') === $container->get(ContainerInterface::class))->toBeTrue();
        expect($container->get('container') === $container)->toBeFalse();
    });

    it('it should call a callback function auto-wiring its arguments', function () {
        $container = new CascadeContainer();
        $container->deferred(DateTime::class, fn () => new DateTime('2025-01-01T12:00:00Z'));
        $container->factory(ContainerInterface::class, fn () => new NullContainer());

        $container->extend(ContainerInterface::class, function (ContainerInterface $container, DateTime $date) {
            expect($date)->toEqual(new DateTime('2025-01-01T12:00:00Z'));
            expect($container)->toBeInstanceOf(NullContainer::class);

            return new ArrayContainer();
        });

        expect($container->get(ContainerInterface::class))->toBeInstanceOf(ArrayContainer::class);
    });
});

describe('CascadeContainer::resolve()', function () {
    it('it should get an instance from the container when defined', function () {
        $container = new CascadeContainer();
        $container->set('container', $container);

        expect($container->resolve('container'))->toBe($container);
    });

    it('it should construct a new instance automatically when not present in the container', function () {
        $container = new CascadeContainer();
        $container->set('container', $container);

        expect($container->resolve(DateTime::class))->toBeInstanceOf(DateTime::class);
    });

    it('it should prefer using the existing defined deferred resolver if present', function () {
        $container = new CascadeContainer();
        $container->deferred(DateTime::class, fn () => new DateTime('2025-01-01T12:00:00Z'));

        expect($container->resolve(DateTime::class))->toEqual(new DateTime('2025-01-01T12:00:00Z'));
    });

    it('it should prefer using the existing defined factory if present', function () {
        $container = new CascadeContainer();
        $container->factory(DateTime::class, fn () => new DateTime('2025-01-01T12:00:00Z'));

        expect($container->resolve(DateTime::class))->toEqual(new DateTime('2025-01-01T12:00:00Z'));
    });
});

describe('CascadeContainer::construct()', function () {
    it('it should construct a new instance', function () {
        $container = new CascadeContainer();

        expect($container->construct(DateTime::class))->toBeInstanceOf(DateTime::class);
    });
});

describe('CascadeContainer::call()', function () {
    it('it should call a callback function auto-wiring its arguments', function () {
        $container = new CascadeContainer();
        $container->deferred(DateTime::class, fn () => new DateTime('2025-01-01T12:00:00Z'));
        $container->factory(ContainerInterface::class, fn () => new NullContainer());

        $container->call(function (DateTime $date, ContainerInterface $container) {
            expect($date)->toEqual(new DateTime('2025-01-01T12:00:00Z'));
            expect($container)->toBeInstanceOf(NullContainer::class);
        });
    });
});

describe('CascadeContainer::cascade()', function () {
    it('it should create a new isolated instance of CascadeContainer, inheriting previously defined services', function () {
        $container = new CascadeContainer();
        $container->deferred(DateTime::class, fn () => new DateTime('2025-01-01T12:00:00Z'));
        $container->factory(ContainerInterface::class, fn () => new NullContainer());

        $layer = $container->cascade();
        $layer->set('logger', fn (string $message) => error_log($message));

        expect($layer->has('logger'))->toBeTrue();
        expect($layer->has(DateTime::class))->toBeTrue();
        expect($layer->has(ContainerInterface::class))->toBeTrue();

        expect($container->has('logger'))->toBeFalse();

        // new services in the parent container are automatically available in the cascade layer
        expect($layer->has('container'))->toBeFalse();
        $container->set('container', $container);
        expect($layer->has('container'))->toBeTrue();
        expect($layer->get('container'))->toBe($container);
    });
});