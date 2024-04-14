<?php

namespace Diana\Tests\Controllers;

use Diana\Routing\Attributes\Delete;
use Diana\Routing\Attributes\Get;
use Diana\Routing\Attributes\Patch;
use Diana\Routing\Attributes\Post;
use Diana\Routing\Attributes\Put;

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