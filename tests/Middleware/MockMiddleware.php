<?php

namespace Diana\Tests\Middleware;

class MockMiddleware
{
    public function handle($request, $next)
    {
        return $next($request);
    }
}