<?php

namespace Diana\Proxies;

use Illuminate\Container\Container;

interface IProxy
{
    public function __construct(Container $container, string $class);

    public function __call($method, $args);
}