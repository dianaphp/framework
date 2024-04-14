<?php

namespace Diana\Tests\Controllers;

use Diana\Routing\Attributes\Command;
use Diana\Routing\Attributes\Middleware;
use Diana\Tests\Middleware\MockMiddleware;

class CommandController
{
    #[Command('test')]
    #[Middleware(MockMiddleware::class)]
    public function test()
    {
        return 'noarg';
    }

    #[Command('testarg', 'opt')]
    public function testArg($opt)
    {
        return 'arg';
    }
}