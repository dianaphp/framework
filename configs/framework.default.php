<?php

return [
    'aliasCachePath' => 'tmp/aliases.php',
    'aliases' => [],
    'bindings' => [
        \Diana\Routing\Router::class => \Diana\Routing\Drivers\FileRouter::class,
        \Diana\Rendering\Contracts\Renderer::class => \Diana\Rendering\Drivers\BladeRenderer::class
    ],
    'entryPoint' => '\App\AppPackage',
    'env' => 'dev',
    'logs' => [
        'error' => 'logs/error.log',
        'access' => 'logs/access.log'
    ],
    'routeCachePath' => 'tmp/routes.php',
    'timezone' => 'Europe/Berlin'
];
