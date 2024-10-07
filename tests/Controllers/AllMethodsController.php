<?php

namespace Diana\Tests\Controllers;

use Diana\Router\Attributes\Delete;
use Diana\Router\Attributes\Get;
use Diana\Router\Attributes\Patch;
use Diana\Router\Attributes\Post;
use Diana\Router\Attributes\Put;

class AllMethodsController
{
    public static $route = '/methods';

    #[Get('/GET')]
    public function GET()
    {
        return 'GET';
    }

    #[Post('/POST')]
    public function POST()
    {
        return 'POST';
    }

    #[Delete('/DELETE')]
    public function DELETE()
    {
        return 'DELETE';
    }

    #[Patch('/PATCH')]
    public function PATCH()
    {
        return 'PATCH';
    }

    #[Put('/PUT')]
    public function PUT()
    {
        return 'PUT';
    }
}