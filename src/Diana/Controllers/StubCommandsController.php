<?php

namespace Diana\Controllers;

use Diana\IO\Response;
use Diana\Routing\Attributes\Command;
use Diana\Runtime\Application;
use Diana\Support\Helpers\Filesystem;

class StubCommandsController
{
    #[Command("create-package", "name")]
    public function makeController(Application $app, string $name)
    {
        // TODO: Outsource
        $stub = str_replace('{{name}}', $name, file_get_contents($app->getPaths()->framework . '/stubs/Package.php.stub'));
        $destination = Filesystem::absPath('./src/' . $name . '.php');
        if (file_exists($destination))
            return new Response("The given file [{$destination}] already exists.", 2);
        file_put_contents($destination, $stub);
    }
}