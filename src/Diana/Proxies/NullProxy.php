<?php

namespace Diana\Proxies;

class NullProxy implements ProxyInterface
{
    public function __call(string $method, array $arguments)
    {
    }

    public static function __callStatic(string $name, array $arguments)
    {
    }
}
