<?php

namespace Diana\Controllers;

use Diana\IO\Response;
use Diana\Router\Attributes\Command;
use Diana\Runtime\Application;
use Diana\Runtime\Kernel;

class StubCommandsController
{
    #[Command("create-package", "name")]
    public function makeController(Application $app, Kernel $kernel, string $name)
    {
        // TODO: Outsource
        $stub = str_replace(
            '{{name}}',
            $name,
            file_get_contents($kernel->path('stubs/Package.php.stub'))
        );
        $destination = $app->path('src/' . $name . '.php');
        if (file_exists($destination)) {
            return new Response("The given file [{$destination}] already exists.", 2);
        }
        file_put_contents($destination, $stub);
    }
}
