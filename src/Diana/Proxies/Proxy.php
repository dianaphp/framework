<?php

namespace Diana\Proxies;

use Illuminate\Container\Container;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Proxy implements IProxy
{
    private mixed $instance;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(protected Container $container, protected string $class)
    {
        $this->instance = $container->get($class);
    }

    public function getInstance(): mixed
    {
        return $this->instance;
    }

    public function __call($method, $args)
    {
        return call_user_func_array([$this->instance, $method], $args);
    }

    public static function __callStatic(string $name, array $arguments)
    {
        return call_user_func_array([static::class, $name], $arguments);
    }
}
