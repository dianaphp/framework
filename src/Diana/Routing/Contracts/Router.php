<?php

namespace Diana\Routing\Contracts;

use Closure;

interface Router
{
    public function load(string $cacheFile, array|Closure $controllers): void;
    public function findRoute(string $route, string $method): ?array;

    public function findCommand(string $commandName, array $args): ?array;
}