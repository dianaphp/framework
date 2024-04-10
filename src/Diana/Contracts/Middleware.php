<?php

namespace Diana\Contracts;

use Closure;
use Diana\IO\Request;

interface Middleware
{
    public function run(Request $request, Closure $next);
}