<?php

namespace Diana\IO;

use Closure;
use Diana\IO\Request;
use Diana\IO\Response;
use Diana\IO\Contracts\Kernel as KernelContract;
use Diana\Runtime\Container;
use RuntimeException;
use Diana\IO\Pipeline;

use Diana\Contracts\Middleware;

class Kernel implements KernelContract
{
    protected array $middleware = [];

    protected Pipeline $pipeline;

    protected $requestHandler;

    public function registerMiddleware(string|Closure $middleware): void
    {
        if (is_string($middleware) && is_a($middleware, Middleware::class))
            throw new RuntimeException('Attempted to register a middleware [' . $middleware . '] that does not implement Middleware.');

        $this->middleware[] = $middleware;
    }

    public function __construct(private Container $container)
    {
    }

    public function run(Request $request): Response
    {
        $this->container->instance(Request::class, $request);

        return (new Pipeline($this->container))
            ->send($request, new Response())
            ->pipe($this->middleware)
            ->expect(Response::class);
    }
}