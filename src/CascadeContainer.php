<?php

namespace Technically\CascadeContainer;

use Exceptions\ServiceNotFound;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Technically\DependencyResolver\Contracts\DependencyResolver as DependencyResolverInterface;
use Technically\DependencyResolver\DependencyResolver;
use Technically\DependencyResolver\Exceptions\CannotAutowireArgument;
use Technically\DependencyResolver\Exceptions\CannotAutowireDependencyArgument;
use Technically\DependencyResolver\Exceptions\ClassCannotBeInstantiated;
use Technically\NullContainer\NullContainer;

final class CascadeContainer implements ContainerInterface
{
    private ContainerInterface $parent;

    private DependencyResolverInterface $resolver;

    /** @var array<string,mixed> */
    private array $instances = [];

    /** @var array<string,callable> */
    private array $resolvers = [];

    /** @var array<string,string> */
    private array $aliases = [];

    public function __construct(
        ContainerInterface | null   $parent = null,
        DependencyResolverInterface $resolver = null,
    ) {
        $this->parent = $parent ?: new NullContainer();
        $this->resolver = $resolver ?: new DependencyResolver($this);
    }

    /**
     * Create a new nested isolated layer of the CascadeContainer.
     * Anything defined in the nested layer will not affect the parent container.
     *
     * @return CascadeContainer
     */
    public function cascade(): self
    {
        return new self($this, $this->resolver);
    }

    public function get(string $id)
    {
        if (array_key_exists($id, $this->aliases)) {
            return $this->get($this->aliases[$id]);
        }

        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (array_key_exists($id, $this->resolvers)) {
            return $this->call($this->resolvers[$id]);
        }

        if ($this->parent->has($id)) {
            return $this->parent->get($id);
        }

        throw new ServiceNotFound($id);
    }

    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->aliases)) {
            return $this->has($this->aliases[$id]);
        }

        return array_key_exists($id, $this->instances)
               || array_key_exists($id, $this->resolvers)
               || $this->parent->has($id);
    }

    /**
     * Bind the given instance as a service to the service container.
     *
     * @param string $id
     * @param mixed  $instance
     * @return void
     */
    public function set(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;

        unset($this->resolvers[$id]);
        unset($this->aliases[$id]);
    }

    public function alias(string $id, string $alias): void
    {
        if ($id === $alias) {
            throw new InvalidArgumentException('Cannot alias a service to itself.');
        }

        $this->aliases[$alias] = $id;
    }

    /**
     * Bind a factory function for the given service ID.
     *
     * The function will be invoked every time the service is requested.
     * If you wish to remember the result of the constructor function,
     * use `->deferred()`.
     *
     * @see deferred()
     *
     * @param string   $id
     * @param callable $constructor
     * @return void
     */
    public function factory(string $id, callable $constructor): void
    {
        $this->resolvers[$id] = $constructor;

        unset($this->aliases[$id]);
    }

    /**
     * Bind a deferred resolver (i.e. one-time factory) for the given service ID.
     *
     * Unlike factories, the deferred resolver will be invoked only once -- for the very first time,
     * and then its result will be remembered as a regular service instance defined in the container.
     *
     * @param string   $id
     * @param callable $resolver
     * @return void
     */
    public function deferred(string $id, callable $resolver): void
    {
        $this->resolvers[$id] = function () use ($id, $resolver): mixed {
            $instance = $this->call($resolver);

            $this->set($id, $instance);

            return $instance;
        };

        unset($this->aliases[$id]);
    }

    /**
     * Resolve the given service instance from the container.
     *
     * It can be either found in the container or constructed on the fly,
     * recursively auto-resolving the required parameters.
     *
     * @param string $id
     * @return mixed
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function resolve(string $id): mixed
    {
        if ($this->has($id)) {
            return $this->get($id);
        }

        return $this->resolver->resolve($id);
    }

    /**
     * Force-construct the given class instance using container state for auto-wiring dependencies.
     *
     * Even if the container already has the instance bound,
     * it will still be instantiated.
     *
     * @param class-string        $className
     * @param array<string,mixed> $bindings
     * @return object
     *
     * @throws ClassCannotBeInstantiated
     * @throws CannotAutowireDependencyArgument
     */
    public function construct(string $className, array $bindings = []): mixed
    {
        return $this->resolver->construct($className, $bindings);
    }

    /**
     * Call the given callable with its arguments automatically wired using the container state.
     *
     * @template T
     * @param callable():T        $callable
     * @param array<string,mixed> $bindings
     * @return T
     *
     * @throws CannotAutowireArgument
     */
    public function call(callable $callable, array $bindings = []): mixed
    {
        return $this->resolver->call($callable, $bindings);
    }
}