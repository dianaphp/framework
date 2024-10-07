<?php

namespace Diana\Tests\Controllers;

use Diana\Router\Attributes\Get;
use Diana\Router\Attributes\Middleware;
use Diana\Tests\Middleware\MockMiddleware;

class GetMethodController
{
    #[Get('/GET')]
    #[Middleware(MockMiddleware::class)]
    public function GET()
    {
        return 'GET';
    }
}
