<?php

namespace Diana\Runtime;

use Diana\Drivers\ContainerInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;

readonly class ContainerProxy implements ContainerInterface
{
    protected Container $container;

    public function __construct()
    {
        $this->container = new Container();
    }

    /**
     * @throws BindingResolutionException
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        return $this->container->make($abstract, $parameters);
    }

    public function call(callable|string $callback, array $parameters = [], $defaultMethod = null): mixed
    {
        return $this->container->call($callback, $parameters, $defaultMethod);
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->container->instance($abstract, $instance);
    }

    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->container->singleton($abstract, $concrete);
    }

    public function get(string $abstract): mixed
    {
        return $this->container->get($abstract);
    }

    public function has(string $abstract): bool
    {
        return $this->container->has($abstract);
    }
}
