<?php

namespace Diana\Proxies;

interface ProxyInterface
{
    public function __call(string $method, array $arguments);

    public static function __callStatic(string $name, array $arguments);
}
