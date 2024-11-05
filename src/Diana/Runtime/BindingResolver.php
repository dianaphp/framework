<?php

namespace Diana\Runtime;

use Diana\Contracts\ContainerContract;
use Diana\IO\Pipeline;
use Diana\IO\Response;

class BindingResolver
{
    public function __construct(protected ContainerContract $container)
    {
    }

    public function resolve(): string
    {
        return $this->container->make(Pipeline::class)
            ->pipe($this->middleware) // global middleware
            ->pipe($route->getMiddleware()) // route middleware
            ->pipe(function () use ($route, $statusCode) {
                return new Response(
                    $this->container->call(
                        $route->getController() . '@' . $route->getMethod(),
                        [
                            'statusCode' => $statusCode,
                            ...$route->getParameters()
                        ]
                    ),
                    $statusCode
                );
            })
            ->expect(Response::class);
    }
}