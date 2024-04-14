<?php

namespace Diana\Tests\Controllers;

use Diana\Routing\Attributes\Delete;
use Diana\Routing\Attributes\Get;
use Diana\Routing\Attributes\Middleware;
use Diana\Routing\Attributes\Patch;
use Diana\Routing\Attributes\Post;
use Diana\Routing\Attributes\Put;
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