<?php

namespace Diana\Tests\Controllers;

use Diana\Router\Attributes\Get;

class SingleRouteControllerWithoutMiddleware
{
    #[Get('/test')]
    public function test(): string
    {
        return 'get';
    }
}
