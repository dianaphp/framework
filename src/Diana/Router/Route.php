<?php

namespace Diana\Router;

use Diana\Drivers\Routing\RouteInterface;

class Route implements RouteInterface
{
    public function __construct(
        protected string $controller,
        protected string $method,
        protected array $middleware = [],
        protected array $params = [],
        protected array $segments = []
    ) {
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getController(): string
    {
        return $this->controller;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setParameters(array $params): void
    {
        $this->params = $params;
    }
    public function getParameters(): array
    {
        return $this->params;
    }

    public function setSegments(array $segments): void
    {
        $this->segments = $segments;
    }
    public function getSegments(): array
    {
        return $this->segments;
    }

    public function toArray(): array
    {
        return [
            'controller' => $this->controller,
            'method' => $this->method,
            'middleware' => $this->middleware,
            'segments' => $this->segments
        ];
    }
}
