<?php

namespace Diana\Router;

use Diana\Contracts\ContainerContract;
use Diana\Contracts\RequestContract;
use Diana\Contracts\RouteContract;
use Diana\Contracts\RouterContract;

class NullRouter implements RouterContract
{
    public function __construct(protected ContainerContract $container)
    {
    }

    public function resolve(RequestContract $request): RouteContract
    {
        return $this->container->make(RouteContract::class, [
            'controller' => self::class,
            'method' => 'shadow'
        ]);
    }

    public function shadow(): void
    {
    }

    public function getErrorRoute(): ?RouteContract
    {
        return null;
    }

    public function getErrorCommandRoute(): ?RouteContract
    {
        return null;
    }
}
