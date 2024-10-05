<?php

namespace Diana\Proxies;

class NullProxy extends Proxy implements IProxy
{
    public function __call($method, $args)
    {
    }

    public static function __callStatic(string $name, array $arguments)
    {
    }
}
