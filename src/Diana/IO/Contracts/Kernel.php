<?php

namespace Diana\IO\Contracts;

use Closure;
use Diana\IO\Request;
use Diana\IO\Response;

interface Kernel
{
    public function handle(Request $request, string $entryPoint): Response;

    public function registerMiddleware(string|Closure $middleware): void;

    public function terminate(Request $request, Response $response): void;
}