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
    protected array $gateMiddleware = [];

    protected array $handlerMiddleware = [];

    protected Pipeline $pipeline;

    protected $requestHandler;

    public function registerGateMiddleware(string|Closure $middleware): void
    {
        if (is_string($middleware) && is_a($middleware, Middleware::class))
            throw new RuntimeException('Attempted to register a middleware [' . $middleware . '] that does not implement Middleware.');

        $this->gateMiddleware[] = $middleware;
    }

    public function registerHandlerMiddleware(string|Closure $middleware): void
    {
        if (is_string($middleware) && is_a($middleware, Middleware::class))
            throw new RuntimeException('Attempted to register a middleware [' . $middleware . '] that does not implement Middleware.');

        $this->handlerMiddleware[] = $middleware;
    }

    public function __construct(private Container $container)
    {
    }

    public function run(Request $request): Response
    {
        $this->container->instance(Request::class, $request);

        return (new Pipeline($this->container))
            ->send($request)
            ->pipe([...$this->gateMiddleware, ...$this->handlerMiddleware])
            ->expect(Response::class);
    }
}