<?php

namespace Diana\IO\Contracts;

use Closure;
use Diana\IO\Request;
use Diana\IO\Response;

interface Kernel
{
    public function run(Request $request): Response;

    public function registerMiddleware(string|Closure $middleware): void;
}