<?php

namespace Diana\Proxies;

use Diana\Drivers\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Proxy implements ProxyInterface
{
    private mixed $instance;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(protected ContainerInterface $container, protected string $class)
    {
        $this->instance = $container->get($class);
    }

    public function getInstance(): mixed
    {
        return $this->instance;
    }

    public function __call(string $method, array $arguments)
    {
        return call_user_func_array([$this->instance, $method], $arguments);
    }

    public static function __callStatic(string $name, array $arguments)
    {
        return call_user_func_array([static::class, $name], $arguments);
    }
}
