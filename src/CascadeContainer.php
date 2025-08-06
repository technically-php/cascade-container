<?php

namespace Technically\CascadeContainer;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Technically\CascadeContainer\Exceptions\ServiceNotFound;
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
    private array $deferred = [];

    /** @var array<string,callable> */
    private array $factories = [];

    /** @var array<string,string> */
    private array $aliases = [];

    /**
     * @param ContainerInterface|array<string,mixed>|null $parent Parent container or initial service instances array map
     * @param DependencyResolverInterface|null            $resolver
     */
    public function __construct(
        ContainerInterface | array | null $parent = null,
        DependencyResolverInterface       $resolver = null,
    ) {
        if (is_array($parent)) {
            foreach ($parent as $id => $instance) {
                if ( ! is_string($id)) {
                    throw new InvalidArgumentException('The service ids have to be strings.');
                }
                $this->instances[$id] = $instance;
            }
        }

        $this->parent   = $parent instanceof ContainerInterface ? $parent : new NullContainer();
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

        if (array_key_exists($id, $this->deferred)) {
            $instance = $this->call($this->deferred[$id]);

            $this->instances[$id] = $instance;

            return $instance;
        }

        if (array_key_exists($id, $this->factories)) {
            return $this->call($this->factories[$id]);
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
               || array_key_exists($id, $this->deferred)
               || array_key_exists($id, $this->factories)
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
        $this->forget($id);

        $this->instances[$id] = $instance;
    }

    public function alias(string $id, string $alias): void
    {
        $this->forget($alias);

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
        $this->forget($id);

        $this->factories[$id] = $constructor;
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
        $this->forget($id);

        $this->deferred[$id] = $resolver;
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
     * Extend the existing service by applying the callback function to it.
     *
     * - Whatever the callback function returns will replace the previous instance.
     * - If the service being extended is defined via a deferred resolver, the extension will become a deferred resolver too.
     * - If the service being extended is defined as a factory, the extension will become a factory too.
     *
     * @param string   $id
     * @param callable $extension
     * @return void
     */
    public function extend(string $id, callable $extension): void
    {
        if (array_key_exists($id, $this->aliases)) {
            $this->extend($this->aliases[$id], $extension);
            return;
        }

        if (array_key_exists($id, $this->instances)) {
            $this->instances[$id] = $this->call($extension, [$this->instances[$id]]);
            return;
        }

        if (array_key_exists($id, $this->deferred)) {
            $resolver            = $this->deferred[$id];
            $this->deferred[$id] = function () use ($extension, $resolver) {
                return $this->call($extension, [$this->call($resolver)]);
            };
            return;
        }

        if (array_key_exists($id, $this->factories)) {
            $factory              = $this->factories[$id];
            $this->factories[$id] = function () use ($extension, $factory) {
                return $this->call($extension, [$this->call($factory)]);
            };
            return;
        }

        if ($this->parent->has($id)) {
            $this->deferred[$id] = function () use ($extension, $id) {
                return $this->call($extension, [$this->parent->get($id)]);
            };
            return;
        }

        throw new ServiceNotFound($id);
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

    /**
     * Erase any definitions for the given service.
     */
    private function forget(string $id): void
    {
        unset($this->aliases[$id]);
        unset($this->instances[$id]);
        unset($this->deferred[$id]);
        unset($this->factories[$id]);
    }
}