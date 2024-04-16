<?php

namespace Diana\Routing\Contracts;

use Closure;
use Diana\IO\Request;

interface Router
{
    public function load(string $cacheFile, array|Closure $controllers): void;
    public function resolve(Request $request): ?array;
}